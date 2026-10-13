"""Opérations de transfert : orchestrent crypto + index + discord, sans rien savoir de l'interface."""
from .download import download_file
from .plan import plan_upload
from .purge import delete_file, purge_file
from .upload import ProgressCB, ResultCB, UploadResult, upload_file, upload_files

__all__ = [
    "ProgressCB", "ResultCB", "UploadResult", "delete_file", "download_file", "plan_upload", "purge_file",
    "upload_file", "upload_files",
]
