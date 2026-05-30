import asyncio
import time
import logging
from typing import Any, Awaitable, Callable

from aiogram import BaseMiddleware
from aiogram.types import TelegramObject, CallbackQuery

logger = logging.getLogger(__name__)

class ThrottleMiddleware(BaseMiddleware):

    def __init__(self, rate: float = 1.0):
        self._rate = rate
        self._last: dict[int, float] = {}

    async def __call__(
        self,
        handler: Callable[[TelegramObject, dict[str, Any]], Awaitable[Any]],
        event: TelegramObject,
        data: dict[str, Any],
    ) -> Any:
        user = data.get("event_from_user")
        if user:
            now = time.monotonic()
            last = self._last.get(user.id, 0)
            if now - last < self._rate:
                if isinstance(event, CallbackQuery):
                    await event.answer()
                return
            self._last[user.id] = now

            if len(self._last) > 10000:
                cutoff = now - self._rate * 10
                self._last = {k: v for k, v in self._last.items() if v > cutoff}

        return await handler(event, data)

class RetryAfterMiddleware(BaseMiddleware):

    async def __call__(
        self,
        handler: Callable[[TelegramObject, dict[str, Any]], Awaitable[Any]],
        event: TelegramObject,
        data: dict[str, Any],
    ) -> Any:
        from aiogram.exceptions import TelegramRetryAfter
        try:
            return await handler(event, data)
        except TelegramRetryAfter as e:
            wait = min(e.retry_after, 30)
            logger.warning(f"Rate limited, retry after {wait}s")
            await asyncio.sleep(wait)
            try:
                return await handler(event, data)
            except TelegramRetryAfter as e2:
                logger.error(f"Still rate limited after retry ({e2.retry_after}s), giving up")
                return None
