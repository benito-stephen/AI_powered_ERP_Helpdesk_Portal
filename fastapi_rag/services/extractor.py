"""
services/extractor.py
Text extraction from PDF, DOCX, and TXT files.
Uses only pure-Python libraries (pypdf, python-docx).
No OCR / Pillow / Tesseract needed.
"""

import logging
from pathlib import Path

logger = logging.getLogger(__name__)


def extract_text(file_path: str) -> list[dict]:
    """
    Extract text from a document file, page by page.

    Returns:
        [{"page_number": int, "page_text": str}, ...]
    """
    path = Path(file_path)
    ext  = path.suffix.lower()

    if ext == ".pdf":
        return _extract_pdf(path)
    elif ext == ".docx":
        return _extract_docx(path)
    elif ext == ".txt":
        return _extract_txt(path)
    else:
        raise ValueError(f"Unsupported file type: {ext}")


import io
from PIL import Image
import pytesseract

# Configure Tesseract path for Windows
pytesseract.pytesseract.tesseract_cmd = r"C:\Program Files\Tesseract-OCR\tesseract.exe"


def _extract_pdf(path: Path) -> list[dict]:
    from pypdf import PdfReader

    reader = PdfReader(str(path))
    pages  = []

    for i, page in enumerate(reader.pages, start=1):
        text = page.extract_text() or ""
        text = text.strip()
        
        # Always check and extract text from images if present
        has_images = False
        try:
            has_images = len(page.images) > 0
        except Exception:
            pass

        if has_images:
            logger.info("PDF page %d of '%s' has images. Running OCR...", i, path.name)
            ocr_texts = []
            try:
                for img_idx, img_file in enumerate(page.images):
                    image_data = img_file.data
                    img = Image.open(io.BytesIO(image_data))
                    ocr_res = pytesseract.image_to_string(img)
                    if ocr_res.strip():
                        ocr_texts.append(ocr_res.strip())
                
                if ocr_texts:
                    ocr_combined = "\n\n[Extracted Image OCR Text]:\n" + "\n\n".join(ocr_texts)
                    if text:
                        text += ocr_combined
                    else:
                        text = ocr_combined.strip()
                    logger.info("Successfully extracted text from page %d images using OCR.", i)
            except pytesseract.TesseractNotFoundError:
                # If there's no digital text and OCR failed due to missing Tesseract, we must fail
                if not text:
                    msg = (
                        "Tesseract OCR is not installed or not configured on the server. "
                        "To support scanned PDFs, please install Tesseract OCR on the server."
                    )
                    logger.error(msg)
                    raise ValueError(msg)
                else:
                    logger.warning("Could not perform OCR on page %d images because Tesseract OCR is not installed.", i)
            except Exception as e:
                logger.warning("OCR failed on page %d: %s", i, e)

        if text:
            pages.append({"page_number": i, "page_text": text})
        else:
            logger.warning("PDF page %d of '%s' has no extractable text or images.", i, path.name)

    if not pages:
        raise ValueError(f"No text could be extracted from '{path.name}'. "
                         "It may be an empty or scanned image-only PDF without OCR support configured.")

    logger.info("Extracted %d page(s) from PDF '%s'.", len(pages), path.name)
    return pages


def _extract_docx(path: Path) -> list[dict]:
    from docx import Document

    doc        = Document(str(path))
    full_text  = "\n".join(p.text for p in doc.paragraphs if p.text.strip())

    if not full_text.strip():
        raise ValueError(f"No text found in DOCX '{path.name}'.")

    # Split into pseudo-pages of ~3000 chars
    PAGE_SIZE = 3000
    pages = []
    for i, start in enumerate(range(0, len(full_text), PAGE_SIZE), start=1):
        chunk = full_text[start : start + PAGE_SIZE].strip()
        if chunk:
            pages.append({"page_number": i, "page_text": chunk})

    logger.info("Extracted %d pseudo-page(s) from DOCX '%s'.", len(pages), path.name)
    return pages


def _extract_txt(path: Path) -> list[dict]:
    text = path.read_text(encoding="utf-8", errors="replace").strip()

    if not text:
        raise ValueError(f"Text file '{path.name}' is empty.")

    PAGE_SIZE = 3000
    pages = []
    for i, start in enumerate(range(0, len(text), PAGE_SIZE), start=1):
        chunk = text[start : start + PAGE_SIZE].strip()
        if chunk:
            pages.append({"page_number": i, "page_text": chunk})

    logger.info("Extracted %d pseudo-page(s) from TXT '%s'.", len(pages), path.name)
    return pages
