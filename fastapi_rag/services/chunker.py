"""
services/chunker.py
Pure-Python text chunker — no LangChain required.

Splits page text into overlapping chunks of fixed character size.
After saving chunks to MySQL, verifies count and clears the
temporary rag_extracted_text rows for that document.
"""

import uuid
import logging
from settings import get_settings
from db.mysql_client import execute_query, execute_many

logger = logging.getLogger(__name__)


def _split_text(text: str, chunk_size: int, chunk_overlap: int) -> list[str]:
    """
    Simple sliding-window character-level splitter.
    Tries to break at sentence boundaries ('. ') when possible.
    """
    chunks = []
    start  = 0
    length = len(text)

    # If text is shorter than chunk size, return it as a single chunk
    if length <= chunk_size:
        return [text] if text.strip() else []

    while start < length:
        end = min(start + chunk_size, length)

        # Try to break at a sentence boundary within the last 200 chars
        if end < length:
            boundary = text.rfind(". ", start, end)
            if boundary != -1 and boundary > start + chunk_size // 2:
                end = boundary + 1   # include the period

        chunk = text[start:end].strip()
        if chunk:
            chunks.append(chunk)

        if end >= length:
            break

        start = end - chunk_overlap  # slide back by overlap
        if start < 0:
            start = 0

    return chunks


def chunk_pages(pages: list[dict], document_id: str, filename: str) -> list[dict]:
    """
    Split pages into overlapping text chunks.

    Returns list of chunk dicts:
        {chunk_id, document_id, filename, page_number, chunk_number, chunk_text}
    """
    cfg     = get_settings()
    chunks  = []
    global_chunk_num = 0

    for page in pages:
        page_num  = page["page_number"]
        page_text = page["page_text"].strip()

        if not page_text:
            continue

        texts = _split_text(page_text, cfg.chunk_size, cfg.chunk_overlap)

        for local_num, text in enumerate(texts, start=1):
            global_chunk_num += 1
            chunks.append({
                "chunk_id"    : str(uuid.uuid4()),
                "document_id" : document_id,
                "filename"    : filename,
                "page_number" : page_num,
                "chunk_number": global_chunk_num,
                "chunk_text"  : text,
            })

    logger.info("Chunked '%s' into %d chunk(s).", filename, len(chunks))
    return chunks


def save_chunks_to_mysql(chunks: list[dict]):
    """Persist chunks into rag_chunks table."""
    sql = """
        INSERT INTO rag_chunks
            (chunk_id, document_id, filename, page_number, chunk_number,
             chunk_text, char_count)
        VALUES (%s, %s, %s, %s, %s, %s, %s)
    """
    rows = [
        (
            c["chunk_id"],
            c["document_id"],
            c["filename"],
            c["page_number"],
            c["chunk_number"],
            c["chunk_text"],
            len(c["chunk_text"]),
        )
        for c in chunks
    ]
    execute_many(sql, rows)
    logger.info("Saved %d chunks to MySQL rag_chunks.", len(rows))


def clear_extracted_text(document_id: str) -> int:
    """
    Verify chunks exist, then delete temporary extracted text for the document.
    Returns the verified chunk count.
    """
    rows = execute_query(
        "SELECT COUNT(*) AS cnt FROM rag_chunks WHERE document_id = %s",
        (document_id,),
        fetch=True,
    )
    count = rows[0]["cnt"] if rows else 0

    if count > 0:
        execute_query(
            "DELETE FROM rag_extracted_text WHERE document_id = %s",
            (document_id,)
        )
        logger.info(
            "Verified %d chunks. Cleared rag_extracted_text for document %s.",
            count, document_id
        )
    else:
        logger.warning(
            "Chunk verification failed for document %s — NOT clearing extracted text.",
            document_id
        )

    return count
