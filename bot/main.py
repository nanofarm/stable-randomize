import asyncio
import logging

from aiogram import Bot, Dispatcher
from aiogram.client.default import DefaultBotProperties
from aiogram.enums import ParseMode
from aiogram.fsm.storage.memory import MemoryStorage
from aiogram.types import MenuButtonWebApp, WebAppInfo, BotCommand

from config import config
from core import ApiClient, start_server
from core.throttle import ThrottleMiddleware, RetryAfterMiddleware
from handlers import (
    common_router,
    channels_router,
    participants_router,
    tasks_router,
    admin_router,
    giveaways_router,
    launch_draw_router,
    photo_router,
)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
)
logger = logging.getLogger(__name__)

class RandomizeBot:

    def __init__(self):
        self.bot = Bot(
            token=config.BOT_TOKEN,
            default=DefaultBotProperties(parse_mode=ParseMode.HTML)
        )
        self.dispatcher = Dispatcher(storage=MemoryStorage())
        self.api = ApiClient()

        self._setup_middleware()
        self._setup_routers()

    def _setup_middleware(self):
        self.dispatcher.message.middleware(ThrottleMiddleware(rate=0.5))
        self.dispatcher.callback_query.middleware(ThrottleMiddleware(rate=0.5))
        self.dispatcher.message.middleware(RetryAfterMiddleware())
        self.dispatcher.callback_query.middleware(RetryAfterMiddleware())

    def _setup_routers(self):
        routers = [
            common_router,
            channels_router,
            participants_router,
            tasks_router,
            admin_router,
            giveaways_router,
            launch_draw_router,
            photo_router,
        ]

        for router in routers:
            self.dispatcher.include_router(router)
            logger.info(f"Router {router.name} connected")

    async def start(self):
        logger.info("🤖 Randomize Bot started")

        try:
            await self.bot.set_my_commands([
                BotCommand(command="start", description="Запустить бота"),
                BotCommand(command="menu", description="Открыть меню"),
                BotCommand(command="help", description="Помощь"),
                BotCommand(command="check_hash", description="Проверить хеши файлов"),
            ])
            logger.info("Bot commands registered")
        except Exception as e:
            logger.warning(f"Failed to set commands: {e}")

        try:
            await self.bot.set_chat_menu_button(
                menu_button=MenuButtonWebApp(
                    text="Открыть бота",
                    web_app=WebAppInfo(url=f"{config.WEBAPP_URL}/app?v=2"),
                )
            )
            logger.info("Menu button set: WebApp")
        except Exception as e:
            logger.warning(f"Failed to set menu button: {e}")

        runner = await start_server(self.bot)
        try:
            await self.dispatcher.start_polling(
                self.bot,
                allowed_updates=["message", "my_chat_member", "callback_query"]
            )
        finally:
            await runner.cleanup()
            await self.api.close()
            await self.bot.session.close()
            logger.info("🤖 Bot stopped")

async def main():
    bot = RandomizeBot()
    await bot.start()

if __name__ == "__main__":
    asyncio.run(main())
