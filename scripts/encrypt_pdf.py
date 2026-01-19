#!/usr/bin/env python3
import os
import sys

VENDOR_DIR = os.path.join(os.path.dirname(__file__), "vendor", "pypdf")
if VENDOR_DIR not in sys.path:
    sys.path.insert(0, VENDOR_DIR)

from pypdf import PdfReader, PdfWriter


def main() -> int:
    if len(sys.argv) < 4:
        sys.stderr.write("Usage: encrypt_pdf.py <input> <output> <password>\n")
        return 2

    input_path = sys.argv[1]
    output_path = sys.argv[2]
    password = sys.argv[3]

    try:
        reader = PdfReader(input_path)
        writer = PdfWriter()
        for page in reader.pages:
            writer.add_page(page)
        if reader.metadata:
            writer.add_metadata(reader.metadata)

        writer.encrypt(
            user_pwd="",
            owner_pwd=password,
            permissions={
                "accessibility": True,
                "extract": False,
                "modify": False,
                "annotate": False,
                "fill": False,
                "assemble": False,
                "print": True,
                "print_high_quality": True,
            },
        )

        with open(output_path, "wb") as f:
            writer.write(f)
        return 0
    except Exception as exc:  # noqa: BLE001
        sys.stderr.write(f"Failed to encrypt PDF: {exc}\n")
        return 4


if __name__ == "__main__":
    raise SystemExit(main())
