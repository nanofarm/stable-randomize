from .common import router as common_router
from .channels import router as channels_router
from .participants import router as participants_router
from .tasks import router as tasks_router
from .admin import router as admin_router
from .giveaways import router as giveaways_router
from .launch_draw import router as launch_draw_router
from .photo import router as photo_router

__all__ = [
    "common_router",
    "channels_router",
    "participants_router",
    "tasks_router",
    "admin_router",
    "giveaways_router",
    "launch_draw_router",
    "photo_router",
]
