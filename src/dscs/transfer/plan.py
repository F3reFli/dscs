"""Calcul de ce qui sera envoyé, avant confirmation."""
from __future__ import annotations

from collections.abc import Iterable
from pathlib import Path

from ..models import UploadItem, UploadPlan
from ..utils import expand


def plan_upload(paths: Iterable[Path], chunk_size: int) -> UploadPlan:
    items = [UploadItem(p, folder, p.stat().st_size) for p, folder in expand(paths)]
    return UploadPlan(items=items, chunk_size=chunk_size)
