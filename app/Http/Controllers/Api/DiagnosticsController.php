<?php

namespace App\Http\Controllers\Api;

use App\Services\DiagnosticRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class DiagnosticsController extends Controller
{
    /** 整個 request body 的位元組上限（約 512 KB） */
    private const MAX_BODY_BYTES = 512 * 1024;

    private const MAX_EVENTS = 500;

    /** 422 回應最多列出幾個欄位的錯誤（一批 500 筆全錯時不回傳上千條訊息） */
    private const MAX_REPORTED_ERRORS = 20;

    /** ISO8601；年份限 1000–2999，落在 MySQL DATETIME 可存範圍內 */
    private const ISO8601_PATTERN = '/^[12]\d{3}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/';

    /**
     * 接收 App 上傳的操作事件日誌，供事後診斷現場問題。
     *
     * **刻意不要求 bearer token**：要診斷的錯誤常發生在尚未授權的畫面。代價是
     * 這是個免驗證的寫入端點，所以只保存「指紋對得上既有席位」的資料，其餘照樣
     * 回 202（`accepted: 0`）。
     *
     * ⚠️ 這支**不得回 401／404**：App 對某些 401／404 會清除本機授權。
     */
    public function __invoke(Request $request, DiagnosticRecorder $recorder): JsonResponse
    {
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return $this->error('PAYLOAD_TOO_LARGE', 'Request payload is too large', 413);
        }

        $validator = Validator::make($request->all(), [
            'fingerprint' => ['required', 'string', 'max:255'],
            'session_id' => ['required', 'string', 'regex:/^[0-9a-f]{32}$/'],
            'started_at' => ['required', 'string', 'regex:'.self::ISO8601_PATTERN, 'date'],
            'app' => ['required', 'array'],
            'app.version' => ['required', 'string', 'max:32'],
            // 空字串會先被 ConvertEmptyStringsToNull 轉成 null。
            'app.env' => ['nullable', 'string', 'in:dev,staging,prod'],
            'app.platform' => ['required', 'string', 'max:32'],
            'app.os_version' => ['nullable', 'string', 'max:64'],
            'app.model' => ['nullable', 'string', 'max:128'],
            'events' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_EVENTS],
            'events.*' => ['required', 'array'],
            'events.*.seq' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'events.*.t' => ['required', 'string', 'regex:'.self::ISO8601_PATTERN, 'date'],
            // 類別刻意不寫死成 enum：App 之後新增類別時後端不必跟著改版。
            'events.*.cat' => ['required', 'string', 'regex:/^[a-z_]{1,32}$/'],
            'events.*.name' => ['required', 'string', 'max:64'],
            'events.*.level' => ['required', 'string', 'in:info,warn,error'],
            'events.*.data' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Request payload is invalid', 422, [
                'details' => array_slice($validator->errors()->toArray(), 0, self::MAX_REPORTED_ERRORS, true),
            ]);
        }

        $accepted = $recorder->record($validator->validated(), $request->ip());

        return response()->json([
            'success' => true,
            'accepted' => $accepted,
        ], 202);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, int $status, array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                ...$meta,
            ],
        ], $status);
    }
}
