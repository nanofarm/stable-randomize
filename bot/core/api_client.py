import asyncio
import json
import logging
from typing import Any

import aiohttp
from aiohttp import ClientError, ClientResponseError, ContentTypeError

from config import config

logger = logging.getLogger(__name__)

class ApiClient:

    _instance: "ApiClient | None" = None

    def __new__(cls) -> "ApiClient":
        if cls._instance is None:
            cls._instance = super().__new__(cls)
            cls._instance._initialized = False
        return cls._instance

    def __init__(self):
        if self._initialized:
            return
        self._initialized = True
        self._session: aiohttp.ClientSession | None = None
        self._timeout = aiohttp.ClientTimeout(total=30)

    def _get_session(self) -> aiohttp.ClientSession:
        if self._session is None or self._session.closed:
            self._session = aiohttp.ClientSession(
                timeout=self._timeout,
                connector=aiohttp.TCPConnector(limit=100, ttl_dns_cache=300),
            )
        return self._session

    async def close(self):
        if self._session and not self._session.closed:
            await self._session.close()

    async def _decode_json(self, response: aiohttp.ClientResponse) -> dict[str, Any]:
        try:
            return await response.json()
        except ContentTypeError:
            text = await response.text()
            logger.warning("API вернул не-JSON ответ: status=%s body=%s", response.status, text[:500])
            return {"ok": False, "error": f"Unexpected non-JSON response ({response.status})"}
        except json.JSONDecodeError:
            text = await response.text()
            logger.warning("API вернул битый JSON: status=%s body=%s", response.status, text[:500])
            return {"ok": False, "error": f"Invalid JSON response ({response.status})"}

    async def request(
        self,
        endpoint: str,
        method: str = "GET",
        data: dict | None = None,
        retries: int = 3,
        backoff: float = 1.0,
    ) -> dict[str, Any]:
        url = f"{config.API_URL.rstrip('/')}/{endpoint.lstrip('/')}"
        method = method.upper()

        for attempt in range(retries):
            try:
                session = self._get_session()
                request_kwargs = {"headers": config.headers}
                if method == "GET":
                    request_kwargs["params"] = data
                else:
                    request_kwargs["json"] = data

                async with session.request(method, url, **request_kwargs) as response:
                    payload = await self._decode_json(response)

                    if response.status < 400:
                        return payload

                    retryable = response.status in {408, 409, 425, 429} or response.status >= 500
                    if retryable and attempt < retries - 1:
                        retry_after = response.headers.get("Retry-After")
                        if retry_after and retry_after.isdigit():
                            delay = max(float(retry_after), backoff * (2 ** attempt))
                        else:
                            delay = backoff * (2 ** attempt)
                        logger.warning(
                            "API ответил %s, повтор через %.1fs (%s/%s)",
                            response.status,
                            delay,
                            attempt + 1,
                            retries,
                        )
                        await asyncio.sleep(delay)
                        continue

                    error_message = payload.get("error") or f"HTTP {response.status}"
                    raise ClientResponseError(
                        request_info=response.request_info,
                        history=response.history,
                        status=response.status,
                        message=str(error_message),
                        headers=response.headers,
                    )

            except (asyncio.TimeoutError, ClientError) as e:
                logger.warning("API попытка %s/%s не удалась: %s", attempt + 1, retries, e)
                if attempt < retries - 1:
                    await asyncio.sleep(backoff * (2 ** attempt))
                else:
                    logger.error("Все попытки исчерпаны: %s", e)
                    return {"ok": False, "error": f"Network error after {retries} attempts"}
            except Exception as e:
                logger.error("Неожиданная ошибка API: %s", e)
                return {"ok": False, "error": str(e)}

        return {"ok": False, "error": "Unknown error"}

    async def get(self, endpoint: str, params: dict | None = None) -> dict[str, Any]:
        return await self.request(endpoint, "GET", params)

    async def post(self, endpoint: str, data: dict | None = None) -> dict[str, Any]:
        return await self.request(endpoint, "POST", data)
