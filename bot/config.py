import os
from dataclasses import dataclass

@dataclass(frozen=True)
class Config:

    BOT_TOKEN: str = os.getenv("BOT_TOKEN", "YOUR_BOT_TOKEN")
    API_URL: str = os.getenv("API_URL", "http://nginx/api")
    BOT_API_TOKEN: str = os.getenv("BOT_API_TOKEN") or os.getenv("TELEGRAM_BOT_TOKEN") or BOT_TOKEN
    WEBAPP_URL: str = os.getenv("WEBAPP_URL", "https://your-domain.com")
    ADMIN_IDS: str = os.getenv("ADMIN_IDS", "")

    @property
    def headers(self) -> dict:
        return {"X-Randomize-Bot-Token": self.BOT_API_TOKEN}

config = Config()
