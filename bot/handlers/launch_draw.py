import logging

from aiogram import Router, F
from aiogram.types import CallbackQuery, Message
from aiogram.fsm.context import FSMContext

from core import ApiClient, DraftStates
from keyboards import MainMenu, GiveawayKeyboard, DraftKeyboard
from keyboards.inline import InlineKeyboardMarkup, InlineKeyboardButton

logger = logging.getLogger(__name__)
router = Router()
api = ApiClient()

@router.callback_query(F.data.startswith("g_launch:"))
async def cb_launch(cb: CallbackQuery):
    gid = cb.data.split(":", 1)[1]
    
    r = await api.get(f"/giveaway/{gid}")
    g = r.get("giveaway", {}) if r.get("ok") else {}
    
    if not g.get("channels"):
        await cb.answer("Сначала подключи хотя бы один канал", show_alert=True)
        return
    
    await cb.message.edit_text(
        "🚀 Опубликовать розыгрыш в каналах?",
        reply_markup=GiveawayKeyboard.confirm_launch(gid)
    )
    await cb.answer()

@router.callback_query(F.data.startswith("g_launch_ok:"))
async def cb_launch_ok(cb: CallbackQuery):
    gid = cb.data.split(":", 1)[1]
    await cb.answer("⏳ Публикую...")
    
    r = await api.post("/giveaway/launch", {
        "giveaway_id": gid,
        "creator_id": cb.from_user.id
    })
    
    if r.get("ok"):
        from services.drafts import pop_active_draft
        pop_active_draft(cb.from_user.id)
        
        await cb.message.edit_text(
            "✅ Розыгрыш запущен и опубликован!",
            reply_markup=InlineKeyboardMarkup(inline_keyboard=[
                [InlineKeyboardButton(text="📄 К розыгрышу", callback_data=f"g_view:{gid}")],
                [InlineKeyboardButton(text="⬅️ В меню", callback_data="menu_root")],
            ])
        )
    else:
        await cb.message.edit_text(
            f"❌ {r.get('error') or r.get('message', 'Ошибка')}",
            reply_markup=MainMenu.back_to_menu()
        )

@router.callback_query(F.data.startswith("g_draw:"))
async def cb_draw(cb: CallbackQuery):
    gid = cb.data.split(":", 1)[1]
    
    await cb.message.edit_text(
        "🎲 Выбрать победителей сейчас? Действие необратимо.",
        reply_markup=GiveawayKeyboard.confirm_draw(gid)
    )
    await cb.answer()

@router.callback_query(F.data.startswith("g_draw_ok:"))
async def cb_draw_ok(cb: CallbackQuery):
    gid = cb.data.split(":", 1)[1]
    await cb.answer("⏳ Разыгрываю...")
    
    r = await api.post("/giveaway/draw", {
        "giveaway_id": gid,
        "creator_id": cb.from_user.id
    })
    
    if r.get("ok"):
        winners = r.get("winners", [])
        w_str = "\n".join(f"  {i+1}. {w.get('user_name', '?')}" for i, w in enumerate(winners)) or "—"
        
        await cb.message.edit_text(
            f"🎉 <b>Розыгрыш завершён!</b>\n\nПобедители:\n{w_str}",
            reply_markup=InlineKeyboardMarkup(inline_keyboard=[
                [InlineKeyboardButton(text="📄 К розыгрышу", callback_data=f"g_view:{gid}")],
                [InlineKeyboardButton(text="⬅️ В меню", callback_data="menu_root")],
            ])
        )
    else:
        await cb.message.edit_text(
            f"❌ {r.get('error', 'Ошибка')}",
            reply_markup=MainMenu.back_to_menu()
        )

@router.callback_query(F.data.startswith("g_bcast:"))
async def cb_bcast(cb: CallbackQuery, state: FSMContext):
    gid = cb.data.split(":", 1)[1]
    
    from core.states import DraftStates
    await state.set_state(DraftStates.broadcast_text)
    await state.update_data(gid=gid)
    
    await cb.message.edit_text(
        "📢 Введи текст для рассылки участникам (до 1000 символов):",
        reply_markup=InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(text="⬅️ Отмена", callback_data=f"g_view:{gid}")]
        ])
    )
    await cb.answer()

@router.message(DraftStates.broadcast_text, F.text)
async def on_bcast_text(msg: Message, state: FSMContext):
    txt = (msg.text or "").strip()
    
    if not txt or len(txt) > 1000:
        await msg.answer("⚠️ От 1 до 1000 символов.")
        return
    
    data = await state.get_data()
    gid = data["gid"]
    await state.clear()
    
    r = await api.post("/giveaway/broadcast", {
        "giveaway_id": gid,
        "creator_id": msg.from_user.id,
        "message": txt
    })
    
    if r.get("ok"):
        await msg.answer(
            f"✅ Рассылка запущена ({r.get('total', '?')} получателей). Доставка в фоне.",
            reply_markup=InlineKeyboardMarkup(inline_keyboard=[
                [InlineKeyboardButton(text="📄 К розыгрышу", callback_data=f"g_view:{gid}")],
                [InlineKeyboardButton(text="⬅️ В меню", callback_data="menu_root")],
            ])
        )
    else:
        await msg.answer(f"❌ {r.get('error', 'Ошибка')}")
