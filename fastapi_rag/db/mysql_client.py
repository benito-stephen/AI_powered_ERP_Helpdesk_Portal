"""
db/mysql_client.py
Pure-Python MySQL connection pool using mysql-connector-python.
Connects to the dedicated erp_rag database.
"""

import logging
import mysql.connector
from mysql.connector import pooling
from settings import get_settings

logger = logging.getLogger(__name__)

_pool = None


def _get_pool():
    global _pool
    if _pool is None:
        cfg = get_settings()
        _pool = pooling.MySQLConnectionPool(
            pool_name="rag_pool",
            pool_size=5,
            host=cfg.rag_db_host,
            port=cfg.rag_db_port,
            database=cfg.rag_db_name,
            user=cfg.rag_db_user,
            password=cfg.rag_db_password,
            autocommit=True,
            charset="utf8mb4",
        )
        logger.info("MySQL pool created for %s:%s/%s", cfg.rag_db_host, cfg.rag_db_port, cfg.rag_db_name)
    return _pool


def execute_query(sql: str, params: tuple = None, fetch: bool = False):
    """Execute a single query. Returns list of dicts if fetch=True."""
    pool = _get_pool()
    conn = pool.get_connection()
    try:
        cursor = conn.cursor(dictionary=True)
        cursor.execute(sql, params or ())
        if fetch:
            result = cursor.fetchall()
            cursor.close()
            return result
        cursor.close()
        return None
    except Exception as exc:
        logger.error("MySQL query error: %s | SQL: %s", exc, sql[:120])
        raise
    finally:
        conn.close()


def execute_many(sql: str, data: list):
    """Execute a batch INSERT/UPDATE."""
    if not data:
        return
    pool = _get_pool()
    conn = pool.get_connection()
    try:
        cursor = conn.cursor()
        cursor.executemany(sql, data)
        conn.commit()
        cursor.close()
    except Exception as exc:
        logger.error("MySQL executemany error: %s", exc)
        raise
    finally:
        conn.close()
