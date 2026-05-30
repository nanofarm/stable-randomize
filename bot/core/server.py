import logging
from aiohttp import web
from aiogram import Bot
from aiogram.types import InlineKeyboardMarkup, InlineKeyboardButton

from config import config

logger = logging.getLogger(__name__)

@web.middleware
async def auth_middleware(request: web.Request, handler):
    token = request.headers.get("X-Randomize-Bot-Token", "")
    if token != config.BOT_API_TOKEN:
        return web.json_response({"ok": False, "error": "Unauthorized"}, status=401)
    return await handler(request)

def build_markup(raw: dict | None) -> InlineKeyboardMarkup | None:
    if not raw:
        return None
    try:
        rows = raw.get("inline_keyboard", [])
        keyboard = [
            [InlineKeyboardButton(**btn) for btn in row]
            for row in rows
        ]
        return InlineKeyboardMarkup(inline_keyboard=keyboard)
    except Exception as e:
        logger.warning(f"build_markup failed: {e}, raw={raw}")
        return None

async def handle_send_post(request: web.Request) -> web.Response:
    bot: Bot = request.app["bot"]
    try:
        data = await request.json()
        chat_id = int(data["chat_id"])
        text = data["text"]
        markup = build_markup(data.get("reply_markup"))
        photo = data.get("photo_url") or data.get("photo")
        
        logger.info(f"SendPost: chat={chat_id}, photo_len={len(str(photo)) if photo else 0}")

        from aiogram.types import URLInputFile
        target_photo = photo
        if photo and isinstance(photo, str) and photo.startswith("http"):
            target_photo = URLInputFile(photo)

        if target_photo:
            try:
                msg = await bot.send_photo(
                    chat_id=chat_id,
                    photo=target_photo,
                    caption=text,
                    parse_mode="HTML",
                    reply_markup=markup,
                )
                logger.info(f"SendPost: photo sent, msg_id={msg.message_id}")
            except Exception as e:
                logger.warning(f"SendPost: photo failed ({photo}), trying text. Error: {e}")
                msg = await bot.send_message(
                    chat_id=chat_id,
                    text=text,
                    parse_mode="HTML",
                    reply_markup=markup,
                )
        else:
            msg = await bot.send_message(
                chat_id=chat_id,
                text=text,
                parse_mode="HTML",
                reply_markup=markup,
            )

        return web.json_response({"ok": True, "message_id": msg.message_id})
    except Exception as e:
        logger.error(f"send-post error: {e}", exc_info=True)
        return web.json_response({"ok": False, "error": str(e)}, status=500)

async def handle_edit_post(request: web.Request) -> web.Response:
    bot: Bot = request.app["bot"]
    try:
        data = await request.json()
        chat_id = int(data["chat_id"])
        message_id = int(data["message_id"])
        text = data["text"]
        markup = build_markup(data.get("reply_markup"))
        photo = data.get("photo") or data.get("photo_url")

        logger.info(f"EditPost: chat={chat_id}, msg={message_id}, has_photo={bool(photo)}")

        from aiogram.types import URLInputFile, InputMediaPhoto
        
        def get_input_file(p):
            if p and isinstance(p, str) and p.startswith("http"):
                return URLInputFile(p)
            return p

        if photo:
            try:
                target_photo = get_input_file(photo)
                await bot.edit_message_media(
                    chat_id=chat_id,
                    message_id=message_id,
                    media=InputMediaPhoto(media=target_photo, caption=text, parse_mode="HTML"),
                    reply_markup=markup,
                )
                logger.info("EditPost: edit_message_media success")
                return web.json_response({"ok": True})
            except Exception as e:
                logger.warning(f"EditPost: edit_message_media failed: {e}")

        try:
            await bot.edit_message_caption(
                chat_id=chat_id,
                message_id=message_id,
                caption=text,
                parse_mode="HTML",
                reply_markup=markup,
            )
            logger.info("EditPost: edit_message_caption success")
            return web.json_response({"ok": True})
        except Exception as e:
            logger.debug(f"EditPost: edit_message_caption failed: {e}")

        try:
            await bot.edit_message_text(
                chat_id=chat_id,
                message_id=message_id,
                text=text,
                parse_mode="HTML",
                reply_markup=markup,
            )
            logger.info("EditPost: edit_message_text success")
            return web.json_response({"ok": True})
        except Exception as e:
            logger.error(f"EditPost: all edit methods failed: {e}")
            return web.json_response({"ok": False, "error": str(e)}, status=500)

    except Exception as e:
        logger.error(f"edit-post error: {e}", exc_info=True)
        return web.json_response({"ok": False, "error": str(e)}, status=500)

async def handle_test_post(request: web.Request) -> web.Response:
    bot: Bot = request.app["bot"]
    try:
        data = await request.json()
        chat_id = int(data["chat_id"])
        text = (
            '🎁 <b>Тестовый розыгрыш</b>\n\n'
            'Описание розыгрыша здесь\n\n'
            '🎁 Приз: <b>iPhone 15</b>\n\n'
            '👥 Участников: <b>0</b>\n\n'
            '👇 Нажми чтобы участвовать!'
        )
        msg = await bot.send_message(chat_id=chat_id, text=text, parse_mode="HTML")
        return web.json_response({"ok": True, "message_id": msg.message_id})
    except Exception as e:
        logger.error(f"test-post error: {e}")
        return web.json_response({"ok": False, "error": str(e)}, status=500)

async def handle_upload_photo(request: web.Request) -> web.Response:
    bot: Bot = request.app["bot"]
    try:
        reader = await request.multipart()

        content = None
        filename = None
        user_id = request.query.get("user_id")

        while True:
            field = await reader.next()
            if field is None:
                break
            if field.name == 'photo':
                filename = field.filename
                content = await field.read()
            elif field.name == 'user_id':
                raw = await field.read()
                user_id = raw.decode() if isinstance(raw, (bytes, bytearray)) else str(raw)

        if content is None:
            logger.warning("upload-photo: no 'photo' field in multipart")
            return web.json_response({"ok": False, "error": "Field 'photo' expected"}, status=400)

        logger.info(f"Received photo upload request, filename={filename}, user_id={user_id}, size={len(content)}")

        from aiogram.types import BufferedInputFile
        input_file = BufferedInputFile(content, filename=filename)

        if user_id:
            target_chat = int(user_id)
        else:
            me = await bot.get_me()
            target_chat = me.id

        logger.info(f"Uploading photo to chat {target_chat}")
        msg = await bot.send_photo(chat_id=target_chat, photo=input_file)
        file_id = msg.photo[-1].file_id
        logger.info(f"Photo uploaded, file_id: {file_id}")

        try:
            await bot.delete_message(chat_id=target_chat, message_id=msg.message_id)
        except Exception as e:
            logger.warning(f"Could not delete temp upload message: {e}")

        return web.json_response({"ok": True, "file_id": file_id})
    except Exception as e:
        logger.error(f"upload-photo error: {e}")
        return web.json_response({"ok": False, "error": str(e)}, status=500)

def create_app(bot: Bot) -> web.Application:
    app = web.Application(middlewares=[auth_middleware])
    app["bot"] = bot
    app.router.add_post("/send-post", handle_send_post)
    app.router.add_post("/edit-post", handle_edit_post)
    app.router.add_post("/test-post", handle_test_post)
    app.router.add_post("/upload-photo", handle_upload_photo)
    return app

async def start_server(bot: Bot, host: str = "0.0.0.0", port: int = 8080):
    app = create_app(bot)
    runner = web.AppRunner(app)
    await runner.setup()
    site = web.TCPSite(runner, host, port)
    await site.start()
    logger.info(f"Internal HTTP server started on {host}:{port}")
    return runner
