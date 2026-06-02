<?php

namespace App\Parsing;

use App\Parsing\Exceptions\ProductParseException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SeleniumSessionBootstrapper
{
    /**
     * @var array<string, mixed>
     */
    private array $lastDiagnostics = [];

    /**
     * @param  array<int, string>  $urls
     * @return array<string, string>
     */
    public function collectCookies(array $urls, string $userAgent): array
    {
        $this->initializeSeleniumDiagnostics([
            "page_url" => $urls[0] ?? null,
        ]);

        $baseUrl = $this->baseUrl();
        $waitMs = $this->waitAfterNavigationMs();
        $sessionId = $this->createSession($baseUrl, $userAgent);

        try {
            foreach ($urls as $url) {
                $this->navigateTo($baseUrl, $sessionId, $url);
                usleep(max(0, $waitMs) * 1000);
            }

            return $this->readCookies($baseUrl, $sessionId);
        } finally {
            $this->deleteSession($baseUrl, $sessionId);
        }
    }

    public function captureNetworkJson(
        string $pageUrl,
        string $urlContains,
        int $timeoutMs = 30000,
    ): string {
        $this->initializeSeleniumDiagnostics([
            "page_url" => $pageUrl,
            "url_contains" => $urlContains,
            "page_status" => null,
            "current_url" => null,
            "document_title" => null,
            "navigator_user_agent" => null,
            "navigator_webdriver" => null,
            "matched_api_url" => null,
            "matched_api_status" => null,
            "matched_api_content_type" => null,
            "matched_api_body_preview" => null,
            "response_kind" => null,
            "block_stage" => null,
            "network_responses" => [],
            "debug_files" => [],
        ]);

        $baseUrl = $this->baseUrl();
        $waitMs = $this->waitAfterNavigationMs();
        $pollMs = $this->capturePollIntervalMs();
        $userAgent = (string) config("services.selenium.user_agent", "");
        $sessionId = $this->createSession($baseUrl, $userAgent);

        try {
            Log::info("Pyaterochka: opening catalog page", [
                "page_url" => $pageUrl,
                "headless" => $this->lastDiagnostics["headless"],
            ]);

            $this->installNetworkCaptureScript($baseUrl, $sessionId, $urlContains);
            $this->navigateTo($baseUrl, $sessionId, $pageUrl);
            usleep(max(0, $waitMs) * 1000);

            $this->collectPageContext($baseUrl, $sessionId);
            $pageHtml = $this->readPageSource($baseUrl, $sessionId);
            $this->saveDebugArtifacts($baseUrl, $sessionId, $pageHtml, null, null);

            if ($this->isPageBlocked($pageHtml)) {
                $this->lastDiagnostics["response_kind"] = "block page";
                $this->lastDiagnostics["block_stage"] = "page";

                Log::warning("Pyaterochka: main page blocked before API capture");

                throw new ProductParseException(
                    "Блокируется сама страница 5ka.ru до загрузки каталога.",
                );
            }

            $capturedResponse = $this->waitForCapturedResponse(
                $baseUrl,
                $sessionId,
                $timeoutMs,
                $pollMs,
            );

            $this->lastDiagnostics["matched_api_url"] = (string) ($capturedResponse["url"] ?? "");
            $this->lastDiagnostics["matched_api_status"] = (int) ($capturedResponse["status"] ?? 0);
            $this->lastDiagnostics["matched_api_content_type"] = (string) ($capturedResponse["contentType"] ?? "");

            $body = (string) ($capturedResponse["body"] ?? "");
            $this->lastDiagnostics["matched_api_body_preview"] = mb_substr($body, 0, 500);
            $this->lastDiagnostics["response_kind"] = $this->detectResponseKind($body);
            $responses = $capturedResponse["responses"] ?? [];
            $this->lastDiagnostics["network_responses"] = is_array($responses) ? $responses : [];

            Log::info("Pyaterochka: found API response", [
                "matched_url" => $this->lastDiagnostics["matched_api_url"],
                "status" => $this->lastDiagnostics["matched_api_status"],
                "content_type" => $this->lastDiagnostics["matched_api_content_type"],
            ]);

            $this->saveDebugArtifacts($baseUrl, $sessionId, $pageHtml, $body, $this->lastDiagnostics["network_responses"]);
            $this->guardAgainstBlocking(
                (int) $this->lastDiagnostics["matched_api_status"],
                $body,
            );

            Log::info("Pyaterochka: JSON successfully obtained");

            return $body;
        } finally {
            $this->deleteSession($baseUrl, $sessionId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function lastDiagnostics(): array
    {
        return $this->lastDiagnostics;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config("services.selenium.url", "http://selenium:4444/wd/hub"), "/");
    }

    private function waitAfterNavigationMs(): int
    {
        return (int) config("services.selenium.wait_after_navigation_ms", 6000);
    }

    private function capturePollIntervalMs(): int
    {
        return (int) config("services.selenium.capture_poll_interval_ms", 500);
    }

    private function debugEnabled(): bool
    {
        return (bool) config("services.selenium.pyaterochka_debug", false);
    }

    private function createSession(string $baseUrl, string $userAgent): string
    {
        $chromeArgs = $this->buildChromeArgs($userAgent);
        $this->lastDiagnostics["headless"] = $this->resolvedHeadless();
        $this->lastDiagnostics["chrome_mode"] = $this->resolvedHeadless() ? "headless" : "headed";
        $this->lastDiagnostics["chrome_args"] = $chromeArgs;

        Log::info("Pyaterochka: starting Selenium Chrome", [
            "headless" => $this->lastDiagnostics["headless"],
            "raw_getenv_headless" => $this->lastDiagnostics["raw_getenv_headless"],
            "env_headless" => $this->lastDiagnostics["env_headless"],
            "config_headless" => $this->lastDiagnostics["config_headless"],
            "chrome_args" => $chromeArgs,
        ]);

        $response = Http::timeout(30)->post($baseUrl . "/session", [
            "capabilities" => [
                "alwaysMatch" => [
                    "browserName" => "chrome",
                    "goog:loggingPrefs" => [
                        "browser" => "ALL",
                        "performance" => "ALL",
                    ],
                    "goog:chromeOptions" => [
                        "args" => $chromeArgs,
                    ],
                ],
            ],
        ]);

        if (!$response->successful()) {
            throw new ProductParseException(
                "Не удалось создать Selenium session: HTTP {$response->status()}",
            );
        }

        $payload = $response->json();
        $sessionId = $payload["value"]["sessionId"]
            ?? $payload["sessionId"]
            ?? null;

        if (!is_string($sessionId) || $sessionId === "") {
            throw new ProductParseException(
                "Selenium не вернул session id.",
            );
        }

        return $sessionId;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function initializeSeleniumDiagnostics(array $overrides = []): void
    {
        $headless = $this->resolvedHeadless();

        $this->lastDiagnostics = $overrides + [
            "raw_getenv_headless" => getenv("SELENIUM_HEADLESS") === false ? null : getenv("SELENIUM_HEADLESS"),
            "env_headless" => env("SELENIUM_HEADLESS"),
            "config_headless" => config("services.selenium.headless", true),
            "headless" => $headless,
            "chrome_mode" => $headless ? "headless" : "headed",
            "chrome_args" => [],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function buildChromeArgs(string $userAgent): array
    {
        $chromeArgs = [
            "--disable-gpu",
            "--window-size=1280,720",
            "--disable-dev-shm-usage",
        ];

        if ($this->resolvedNoSandbox()) {
            $chromeArgs[] = "--no-sandbox";
        }

        if ($this->resolvedHeadless()) {
            $chromeArgs[] = "--headless=new";
        }

        if ($userAgent !== "") {
            $chromeArgs[] = "--user-agent=" . $userAgent;
        }

        foreach ($this->customBrowserArguments() as $argument) {
            if ($argument === "" || in_array($argument, $chromeArgs, true)) {
                continue;
            }

            $chromeArgs[] = $argument;
        }

        return $chromeArgs;
    }

    private function resolvedHeadless(): bool
    {
        return $this->normalizeBoolean(config("services.selenium.headless", true), true);
    }

    private function resolvedNoSandbox(): bool
    {
        return $this->normalizeBoolean(config("services.selenium.no_sandbox", true), true);
    }

    /**
     * @return array<int, string>
     */
    private function customBrowserArguments(): array
    {
        $rawArguments = (string) config("services.selenium.browser_arguments", "");

        if (trim($rawArguments) === "") {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $argument) => trim($argument),
            explode(",", $rawArguments),
        ), static fn (string $argument) => $argument !== ""));
    }

    private function normalizeBoolean(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return $default;
        }

        $normalized = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $normalized ?? $default;
    }

    private function navigateTo(string $baseUrl, string $sessionId, string $url): void
    {
        $response = Http::timeout(30)->post(
            "{$baseUrl}/session/{$sessionId}/url",
            ["url" => $url],
        );

        if (!$response->successful()) {
            throw new ProductParseException(
                "Selenium не смог открыть страницу: HTTP {$response->status()}",
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function readCookies(string $baseUrl, string $sessionId): array
    {
        $response = Http::timeout(30)->get("{$baseUrl}/session/{$sessionId}/cookie");

        if (!$response->successful()) {
            throw new ProductParseException(
                "Selenium не смог получить cookies: HTTP {$response->status()}",
            );
        }

        $payload = $response->json();
        $rawCookies = $payload["value"] ?? [];
        $cookies = [];

        foreach ($rawCookies as $cookie) {
            if (!is_array($cookie)) {
                continue;
            }

            $name = $cookie["name"] ?? null;
            $value = $cookie["value"] ?? null;

            if (!is_string($name) || $name === "" || !is_string($value)) {
                continue;
            }

            $cookies[$name] = $value;
        }

        return $cookies;
    }

    private function installNetworkCaptureScript(
        string $baseUrl,
        string $sessionId,
        string $urlContains,
    ): void {
        $response = Http::timeout(60)->post(
            "{$baseUrl}/session/{$sessionId}/goog/cdp/execute",
            [
                "cmd" => "Page.addScriptToEvaluateOnNewDocument",
                "params" => [
                    "source" => $this->networkCaptureScript($urlContains),
                ],
            ],
        );

        if (!$response->successful()) {
            throw new ProductParseException(
                "Не удалось настроить перехват network response в Selenium: HTTP {$response->status()}",
            );
        }
    }

    /**
     * @return array{url:string|null,status:int|string|null,body:string|null,contentType:string|null,responses?:array<int, array<string, mixed>>}|null
     */
    private function waitForCapturedResponse(
        string $baseUrl,
        string $sessionId,
        int $timeoutMs,
        int $pollMs,
    ): ?array {
        $deadline = microtime(true) + max(1, $timeoutMs) / 1000;

        while (microtime(true) < $deadline) {
            $response = Http::timeout(30)->post(
                "{$baseUrl}/session/{$sessionId}/execute/sync",
                [
                    "script" => <<<'JS'
return {
  matched: window.__codexCapturedNetworkJson || null,
  responses: window.__codexNetworkResponses || []
};
JS,
                    "args" => [],
                ],
            );

            if (!$response->successful()) {
                throw new ProductParseException(
                    "Не удалось проверить перехваченный network response: HTTP {$response->status()}",
                );
            }

            $payload = $response->json();
            $value = $payload["value"] ?? null;

            if (is_array($value)) {
                $responses = $value["responses"] ?? [];
                if (is_array($responses)) {
                    $this->lastDiagnostics["network_responses"] = $responses;
                }

                $matched = $value["matched"] ?? null;
                if (is_array($matched) && $matched !== []) {
                    if (!isset($matched["responses"])) {
                        $matched["responses"] = is_array($responses) ? $responses : [];
                    }

                    return $matched;
                }
            }

            usleep(max(100, $pollMs) * 1000);
        }

        Log::warning("Pyaterochka: timeout waiting for API response");

        throw new ProductParseException(
            "Timeout ожидания API response каталога Пятёрочки.",
        );
    }

    private function collectPageContext(string $baseUrl, string $sessionId): void
    {
        $this->lastDiagnostics["current_url"] = $this->readCurrentUrl($baseUrl, $sessionId);
        $this->lastDiagnostics["document_title"] = $this->readTitle($baseUrl, $sessionId);

        $navigator = $this->readNavigatorDetails($baseUrl, $sessionId);
        $this->lastDiagnostics["navigator_user_agent"] = $navigator["userAgent"] ?? null;
        $this->lastDiagnostics["navigator_webdriver"] = $navigator["webdriver"] ?? null;
    }

    private function readCurrentUrl(string $baseUrl, string $sessionId): ?string
    {
        $response = Http::timeout(30)->get("{$baseUrl}/session/{$sessionId}/url");
        if (!$response->successful()) {
            return null;
        }

        $payload = $response->json();
        $value = $payload["value"] ?? null;

        return is_string($value) ? $value : null;
    }

    private function readTitle(string $baseUrl, string $sessionId): ?string
    {
        $response = Http::timeout(30)->get("{$baseUrl}/session/{$sessionId}/title");
        if (!$response->successful()) {
            return null;
        }

        $payload = $response->json();
        $value = $payload["value"] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readNavigatorDetails(string $baseUrl, string $sessionId): array
    {
        $response = Http::timeout(30)->post(
            "{$baseUrl}/session/{$sessionId}/execute/sync",
            [
                "script" => <<<'JS'
return {
  userAgent: navigator.userAgent,
  webdriver: navigator.webdriver === true
};
JS,
                "args" => [],
            ],
        );

        if (!$response->successful()) {
            return [];
        }

        $payload = $response->json();
        $value = $payload["value"] ?? null;

        return is_array($value) ? $value : [];
    }

    private function readPageSource(string $baseUrl, string $sessionId): string
    {
        $response = Http::timeout(30)->get("{$baseUrl}/session/{$sessionId}/source");

        if (!$response->successful()) {
            throw new ProductParseException(
                "Selenium не смог получить HTML страницы: HTTP {$response->status()}",
            );
        }

        $payload = $response->json();
        $source = $payload["value"] ?? null;

        if (!is_string($source) || $source === "") {
            throw new ProductParseException(
                "Selenium вернул пустой HTML страницы.",
            );
        }

        return $source;
    }

    private function readScreenshot(string $baseUrl, string $sessionId): ?string
    {
        $response = Http::timeout(30)->get("{$baseUrl}/session/{$sessionId}/screenshot");
        if (!$response->successful()) {
            return null;
        }

        $payload = $response->json();
        $value = $payload["value"] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $networkResponses
     */
    private function saveDebugArtifacts(
        string $baseUrl,
        string $sessionId,
        string $pageHtml,
        ?string $apiBody,
        ?array $networkResponses,
    ): void {
        if (!$this->debugEnabled()) {
            return;
        }

        $directory = storage_path("app/debug");
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            return;
        }

        $htmlPath = $directory . "/pyaterochka-page.html";
        if (@file_put_contents($htmlPath, $pageHtml) !== false) {
            $this->lastDiagnostics["debug_files"]["page_html"] = $htmlPath;
        }

        $screenshotBase64 = $this->readScreenshot($baseUrl, $sessionId);
        if (is_string($screenshotBase64) && $screenshotBase64 !== "") {
            $pngPath = $directory . "/pyaterochka-page.png";
            $decoded = base64_decode($screenshotBase64, true);

            if ($decoded !== false && @file_put_contents($pngPath, $decoded) !== false) {
                $this->lastDiagnostics["debug_files"]["screenshot"] = $pngPath;
            }
        }

        if ($apiBody !== null) {
            $extension = $this->detectResponseKind($apiBody) === "json" ? "json" : "html";
            $apiPath = $directory . "/pyaterochka-api-response." . $extension;

            if (@file_put_contents($apiPath, $apiBody) !== false) {
                $this->lastDiagnostics["debug_files"]["api_response"] = $apiPath;
            }
        }

        if ($networkResponses !== null) {
            $networkPath = $directory . "/pyaterochka-network.json";

            if (@file_put_contents(
                $networkPath,
                json_encode($networkResponses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            ) !== false) {
                $this->lastDiagnostics["debug_files"]["network"] = $networkPath;
            }
        }
    }

    private function deleteSession(string $baseUrl, string $sessionId): void
    {
        Http::timeout(15)->delete("{$baseUrl}/session/{$sessionId}");
    }

    private function networkCaptureScript(string $urlContains): string
    {
        $encodedUrlContains = json_encode($urlContains, JSON_THROW_ON_ERROR);

        return <<<JS
(function () {
  const urlContains = {$encodedUrlContains};
  window.__codexCapturedNetworkJson = null;
  window.__codexNetworkResponses = [];

  const shouldLog = (url) => {
    if (typeof url !== "string" || url.length === 0) {
      return false;
    }

    return url.includes("5ka.ru")
      || url.includes("5d.5ka.ru")
      || url.includes("/api/")
      || url.includes("catalog");
  };

  const matches = (url) => typeof url === "string" && url.includes(urlContains);
  const pushResponse = (payload) => {
    if (shouldLog(payload.url)) {
      window.__codexNetworkResponses.push(payload);
    }
  };
  const store = (payload) => {
    if (window.__codexCapturedNetworkJson === null) {
      window.__codexCapturedNetworkJson = payload;
    }
  };

  const originalFetch = window.fetch;
  window.fetch = async function (...args) {
    const response = await originalFetch.apply(this, args);

    try {
      const requestUrl = typeof args[0] === "string"
        ? args[0]
        : (args[0] && args[0].url) || "";
      const clone = response.clone();
      const body = await clone.text();
      const payload = {
        url: requestUrl,
        status: response.status,
        contentType: response.headers.get("content-type") || "",
        bodyPreview: body.slice(0, 500),
      };

      pushResponse(payload);

      if (matches(requestUrl)) {
        store({
          url: requestUrl,
          status: response.status,
          contentType: response.headers.get("content-type") || "",
          body: body,
        });
      }
    } catch (error) {}

    return response;
  };

  const xhrOpen = XMLHttpRequest.prototype.open;
  const xhrSend = XMLHttpRequest.prototype.send;

  XMLHttpRequest.prototype.open = function (method, url) {
    this.__codexRequestUrl = url;

    return xhrOpen.apply(this, arguments);
  };

  XMLHttpRequest.prototype.send = function () {
    this.addEventListener("load", function () {
      try {
        const payload = {
          url: this.__codexRequestUrl || "",
          status: this.status,
          contentType: this.getResponseHeader("content-type") || "",
          bodyPreview: (this.responseText || "").slice(0, 500),
        };

        pushResponse(payload);

        if (matches(this.__codexRequestUrl)) {
          store({
            url: this.__codexRequestUrl,
            status: this.status,
            contentType: this.getResponseHeader("content-type") || "",
            body: this.responseText || "",
          });
        }
      } catch (error) {}
    });

    return xhrSend.apply(this, arguments);
  };
})();
JS;
    }

    private function guardAgainstBlocking(int $status, string $body): void
    {
        if (in_array($status, [403, 429], true) || $this->isApiBlocked($body)) {
            $this->lastDiagnostics["block_stage"] = "api";

            Log::warning("Pyaterochka: API blocking detected", [
                "status" => $status,
            ]);

            throw new ProductParseException(
                "Блокируется API 5d.5ka.ru после загрузки страницы.",
            );
        }
    }

    private function isPageBlocked(string $body): bool
    {
        return str_contains($body, "Проблемы со связью")
            || str_contains($body, "Проверьте настройки интернета и VPN")
            || str_contains($body, "Service_connect@x5.ru");
    }

    private function isApiBlocked(string $body): bool
    {
        $trimmed = ltrim($body);

        if ($trimmed !== "" && str_starts_with(strtolower($trimmed), "<html")) {
            return true;
        }

        return $this->isPageBlocked($body);
    }

    private function detectResponseKind(string $body): string
    {
        if ($this->isApiBlocked($body)) {
            return "block page";
        }

        $trimmed = ltrim($body);
        if ($trimmed !== "" && ($trimmed[0] === "{" || $trimmed[0] === "[")) {
            return "json";
        }

        if ($trimmed !== "" && str_starts_with(strtolower($trimmed), "<html")) {
            return "html";
        }

        return "unknown";
    }
}
