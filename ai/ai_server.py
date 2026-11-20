
from flask import Flask, request, jsonify
import sys

import hybrid_matching as ai

app = Flask(__name__)


# --------------------------------------------------
# HEALTH CHECK
# --------------------------------------------------

@app.route("/health", methods=["GET"])
def health():
    return jsonify({
        "success": True,
        "message": "RTU AI Worker is running."
    })


# --------------------------------------------------
# AI MATCHING ENDPOINT
# --------------------------------------------------

@app.route("/match", methods=["POST"])
def match():
    try:
        data = request.get_json(silent=True)

        if not isinstance(data, dict):
            return jsonify({
                "success": False,
                "error": "Invalid JSON request."
            }), 400

        source = data.get("source")
        candidates = data.get("candidates")

        if not isinstance(source, dict):
            return jsonify({
                "success": False,
                "error": "Invalid source item."
            }), 400

        if not isinstance(candidates, list):
            return jsonify({
                "success": False,
                "error": "Invalid candidates list."
            }), 400

        if not source.get("image"):
            return jsonify({
                "success": False,
                "error": "Source image is missing."
            }), 400

        print(
            f"Received request: {len(candidates)} candidates",
            file=sys.stderr,
            flush=True
        )

        # Extract source image features once.
        source_image = ai.open_image(source["image"])

        source_features = {
            "dino": ai.get_dino_embedding(source_image),
            "clip_image": ai.get_clip_image_embedding(source_image)
        }

        # Calculate source text embedding only once.
        source_description = ai.build_description(source)

        source_text_embedding = ai.get_clip_text_embedding(
            source_description
        )

        results = []

        for index, candidate in enumerate(candidates, start=1):
            try:
                result = ai.compare_items(
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
                f"Processed {index}/{len(candidates)} candidates",
                file=sys.stderr,
                flush=True
            )

        results.sort(
            key=lambda item: item.get("final_score", -1),
            reverse=True
        )

        return jsonify({
            "success": True,
            "results": results
        })

    except Exception as error:
        print(
            f"AI matching error: {error}",
            file=sys.stderr,
            flush=True
        )

        return jsonify({
            "success": False,
            "error": str(error)
        }), 500


# --------------------------------------------------
# START SERVER
# --------------------------------------------------

if __name__ == "__main__":
    print(
        "Starting RTU AI Worker on http://127.0.0.1:5001",
        flush=True
    )

    app.run(
        host="127.0.0.1",
        port=5001,
        debug=False,
        threaded=False,
        use_reloader=False
    )
