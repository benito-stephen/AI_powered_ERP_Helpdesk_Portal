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


def _extract_pdf(path: Path) -> list[dict]:
    from pypdf import PdfReader

    reader = PdfReader(str(path))
    pages  = []

    for i, page in enumerate(reader.pages, start=1):
        text = page.extract_text() or ""
        text = text.strip()
        if text:
            pages.append({"page_number": i, "page_text": text})
        else:
            logger.warning("PDF page %d of '%s' has no extractable text.", i, path.name)

    if not pages:
        raise ValueError(f"No text could be extracted from '{path.name}'. "
                         "It may be a scanned image-only PDF.")

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
