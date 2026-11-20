
import os
import json
import sys
import re
from pathlib import Path
from difflib import SequenceMatcher

import torch
import torch.nn.functional as F
from PIL import Image
from transformers import (
    AutoImageProcessor,
    AutoModel,
    CLIPProcessor,
    CLIPModel
)

# --------------------------------------------------
# CONFIGURATION
# --------------------------------------------------

DEVICE = torch.device("cpu")

DINOV2_NAME = os.path.join(
    os.path.dirname(__file__),
    "models",
    "dinov2-small"
)

CLIP_NAME = os.path.join(
    os.path.dirname(__file__),
    "models",
    "clip-vit-base-patch32"
)

WEIGHTS = {
    "dinov2": 0.35,
    "clip_image": 0.25,
    "clip_text": 0.20,
    "metadata": 0.20
}

# --------------------------------------------------
# LOAD MODELS
# --------------------------------------------------

print("Loading DINOv2...", file=sys.stderr)

dino_processor = AutoImageProcessor.from_pretrained(DINOV2_NAME)
dino_model = AutoModel.from_pretrained(DINOV2_NAME)
dino_model.to(DEVICE)
dino_model.eval()

print("Loading CLIP...", file=sys.stderr)

clip_processor = CLIPProcessor.from_pretrained(CLIP_NAME)
clip_model = CLIPModel.from_pretrained(CLIP_NAME)
clip_model.to(DEVICE)
clip_model.eval()

print("AI models loaded successfully.", file=sys.stderr)

# --------------------------------------------------
# IMAGE HANDLING
# --------------------------------------------------

def open_image(image_path):
    path = Path(image_path)

    if not path.is_file():
        raise FileNotFoundError(
            f"Image not found: {image_path}"
        )

    with Image.open(path) as image:
        return image.convert("RGB")


# --------------------------------------------------
# IMAGE EMBEDDINGS
# --------------------------------------------------

def get_dino_embedding(image):
    inputs = dino_processor(
        images=image,
        return_tensors="pt"
    )

    inputs = {
        key: value.to(DEVICE)
        for key, value in inputs.items()
    }

    with torch.inference_mode():
        outputs = dino_model(**inputs)
        embedding = outputs.last_hidden_state[:, 0, :]

    return F.normalize(embedding, p=2, dim=1)


def get_clip_image_embedding(image):
    inputs = clip_processor(
        images=image,
        return_tensors="pt"
    )

    inputs = {
        key: value.to(DEVICE)
        for key, value in inputs.items()
    }

    with torch.inference_mode():
        outputs = clip_model.vision_model(
            pixel_values=inputs["pixel_values"]
        )

        embedding = clip_model.visual_projection(
            outputs.pooler_output
        )

    return F.normalize(embedding, p=2, dim=1)


def get_clip_text_embedding(text):
    if not text.strip():
        return None

    inputs = clip_processor(
        text=[text],
        return_tensors="pt",
        padding=True,
        truncation=True
    )

    inputs = {
        key: value.to(DEVICE)
        for key, value in inputs.items()
    }

    with torch.inference_mode():
        outputs = clip_model.text_model(
            input_ids=inputs["input_ids"],
            attention_mask=inputs.get("attention_mask")
        )

        embedding = clip_model.text_projection(
            outputs.pooler_output
        )

    return F.normalize(embedding, p=2, dim=1)


# --------------------------------------------------
# SIMILARITY
# --------------------------------------------------

def cosine_score(embedding1, embedding2):
    similarity = F.cosine_similarity(
        embedding1,
        embedding2
    ).item()

    return max(
        0.0,
        min(100.0, (similarity + 1.0) * 50.0)
    )


# --------------------------------------------------
# METADATA COMPARISON
# --------------------------------------------------

def normalize_text(value):
    if value is None:
        return ""

    return re.sub(
        r"[^a-z0-9\s]",
        " ",
        str(value).lower()
    ).strip()


def text_similarity(first, second):
    first = normalize_text(first)
    second = normalize_text(second)

    if not first or not second:
        return None

    sequence_score = SequenceMatcher(
        None,
        first,
        second
    ).ratio()

    first_words = set(first.split())
    second_words = set(second.split())

    union = first_words | second_words

    word_score = (
        len(first_words & second_words) / len(union)
        if union else 0.0
    )

    return (
        sequence_score * 0.5 +
        word_score * 0.5
    ) * 100


def metadata_score(source, candidate):
    comparisons = [
        ("item_name", 0.30),
        ("category", 0.25),
        ("description", 0.20),
        ("color", 0.15),
        ("location", 0.10)
    ]

    total = 0.0
    available_weight = 0.0

    for field, weight in comparisons:
        score = text_similarity(
            source.get(field),
            candidate.get(field)
        )

        if score is not None:
            total += score * weight
            available_weight += weight

    if available_weight == 0:
        return 0.0

    return total / available_weight


# --------------------------------------------------
# BUILD CLIP DESCRIPTION
# --------------------------------------------------

def build_description(item):
    fields = [
        ("item", "item_name"),
        ("category", "category"),
        ("description", "description"),
        ("color", "color"),
        ("location", "location")
    ]

    parts = []

    for label, field in fields:
        value = normalize_text(item.get(field))

        if value:
            parts.append(f"{label}: {value}")

    return ". ".join(parts)


# --------------------------------------------------
# HYBRID MATCHING
# --------------------------------------------------

def compare_items(
    source,
    candidate,
    source_features,
    source_text_embedding
):
    candidate_image = open_image(candidate["image"])

    candidate_dino = get_dino_embedding(candidate_image)
    candidate_clip = get_clip_image_embedding(candidate_image)

    dino_score = cosine_score(
        source_features["dino"],
        candidate_dino
    )

    clip_image_score = cosine_score(
        source_features["clip_image"],
        candidate_clip
    )

    # Reuse the source text embedding.
    # No need to calculate it again for every candidate.
    if source_text_embedding is not None:
        clip_text_score = cosine_score(
            source_text_embedding,
            candidate_clip
        )
    else:
        clip_text_score = None

    meta_score = metadata_score(source, candidate)

    available = {
        "dinov2": (
            dino_score,
            WEIGHTS["dinov2"]
        ),
        "clip_image": (
            clip_image_score,
            WEIGHTS["clip_image"]
        ),
        "metadata": (
            meta_score,
            WEIGHTS["metadata"]
        )
    }

    if clip_text_score is not None:
        available["clip_text"] = (
            clip_text_score,
            WEIGHTS["clip_text"]
        )

    total_weight = sum(
        weight
        for _, weight in available.values()
    )

    final_score = sum(
        score * weight
        for score, weight in available.values()
    ) / total_weight

    return {
        "candidate_id": candidate.get("id"),
        "final_score": round(final_score, 2),
        "components": {
            "dinov2": round(dino_score, 2),
            "clip_image": round(clip_image_score, 2),
            "clip_text": (
                round(clip_text_score, 2)
                if clip_text_score is not None
                else None
            ),
            "metadata": round(meta_score, 2)
        }
    }


# --------------------------------------------------
# MAIN
# --------------------------------------------------

def main():
    if len(sys.argv) != 2:
        raise ValueError(
            "Usage: hybrid_matching.py input.json"
        )

    input_path = Path(sys.argv[1])

    with input_path.open("r", encoding="utf-8") as file:
        data = json.load(file)

    source = data["source"]
    candidates = data["candidates"]

    if not source.get("image"):
        raise ValueError("Source image is missing.")

    source_image = open_image(source["image"])

    print(
        "Extracting source features...",
        file=sys.stderr
    )

    source_features = {
        "dino": get_dino_embedding(source_image),
        "clip_image": get_clip_image_embedding(source_image)
    }

    # Calculate source text embedding only once.
    source_description = build_description(source)

    source_text_embedding = get_clip_text_embedding(
        source_description
    )

    print(
        f"Comparing {len(candidates)} candidate items...",
        file=sys.stderr
    )

    results = []

    for index, candidate in enumerate(candidates, start=1):
        try:
            result = compare_items(
                source,
                candidate,
                source_features,
                source_text_embedding
            )

            results.append(result)

        except Exception as error:
            results.append({
                "candidate_id": candidate.get("id"),
                "error": str(error)
            })

        print(
            f"Processed {index}/{len(candidates)} candidates.",
            file=sys.stderr
        )

    results.sort(
        key=lambda item: item.get("final_score", -1),
        reverse=True
    )

    print(json.dumps({
        "success": True,
        "results": results
    }))


if __name__ == "__main__":
    try:
        main()

    except Exception as error:
        print(json.dumps({
            "success": False,
            "error": str(error)
        }))

        sys.exit(1)
