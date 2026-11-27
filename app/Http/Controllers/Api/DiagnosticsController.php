<?php

namespace App\Http\Controllers\Api;

use App\Services\DiagnosticRecorder;
use App\Services\DiagnosticUploadResult;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DiagnosticsController extends Controller
{
    /** 整個 request body 的位元組上限（約 512 KB） */
    private const MAX_BODY_BYTES = 512 * 1024;

    private const MAX_EVENTS = 500;

    private const APP_FIELDS = ['version', 'env', 'platform', 'os_version', 'model'];

    private const EVENT_FIELDS = ['seq', 't', 'cat', 'name', 'level', 'data'];

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
     * 驗證分兩段：先驗頂層欄位並查指紋，指紋對不上就直接回 202，**不驗事件內容**。
     * 逐筆驗證 500 筆事件是這支端點最貴的一步（實測 0.2–1 秒），而節流鍵含呼叫端
     * 自填的指紋、換個指紋就有新額度，所以不能讓陌生指紋付得起這個成本。
     *
     * 每次上傳的處理結果都記到 `api` channel（`[api] diagnostics upload`），
     * 因為回應的 `accepted: 0` 有三種成因，事後只能靠這行分辨。
     *
     * ⚠️ 這支**不得回 401／404**：App 對某些 401／404 會清除本機授權。
     */
    public function __invoke(Request $request, DiagnosticRecorder $recorder): JsonResponse
    {
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return $this->error('PAYLOAD_TOO_LARGE', 'Request payload is too large', 413);
        }

        $app = $request->input('app');
        $rawEvents = $request->input('events');

        $envelope = Validator::make([
            'fingerprint' => $this->shallow($request->input('fingerprint')),
            'session_id' => $this->shallow($request->input('session_id')),
            'started_at' => $this->shallow($request->input('started_at')),
            'app' => is_array($app) ? array_map($this->shallow(...), Arr::only($app, self::APP_FIELDS)) : $app,
            // 這一段只看「是不是 1–500 筆的 list」，事件內容留到確認指紋之後。
            'events' => is_array($rawEvents) ? array_fill_keys(array_keys($rawEvents), null) : $rawEvents,
        ], [
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
        ]);

        if ($envelope->fails()) {
            return $this->validationFailed($envelope);
        }

        $payload = $envelope->validated();
        $usage = $recorder->findUsage($payload['fingerprint']);

        if ($usage === null) {
            return $this->respond($payload, new DiagnosticUploadResult(
                DiagnosticUploadResult::OUTCOME_UNKNOWN_FINGERPRINT,
                0,
                ['received' => count($rawEvents)],
            ));
        }

        $events = Validator::make(['events' => array_map(
            fn (mixed $event): mixed => is_array($event)
                ? array_map($this->shallow(...), Arr::only($event, self::EVENT_FIELDS))
                : $event,
            $rawEvents,
        )], [
            'events.*' => ['required', 'array'],
            'events.*.seq' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'events.*.t' => ['required', 'string', 'regex:'.self::ISO8601_PATTERN, 'date'],
            // 類別刻意不寫死成 enum：App 之後新增類別時後端不必跟著改版。
            'events.*.cat' => ['required', 'string', 'regex:/^[a-z_]{1,32}$/'],
            'events.*.name' => ['required', 'string', 'max:64'],
            'events.*.level' => ['required', 'string', 'in:info,warn,error'],
            'events.*.data' => ['nullable', 'array'],
        ]);

        if ($events->fails()) {
            return $this->validationFailed($events);
        }

        // 純量欄位取驗證後的值；`data` 驗的是壓平後的替身，內容要從原始輸入放回來。
        $payload['events'] = array_map(
            fn (array $event, array $raw): array => [...$event, 'data' => $raw['data'] ?? null],
            $events->validated()['events'],
            $rawEvents,
        );

        return $this->respond($payload, $recorder->record($payload, $usage, $request->ip()));
    }

    /**
     * 交給 validator 之前先把巢狀內容換成空陣列。
     *
     * Validator 會走訪拿到的每一個巢狀節點，規則沒列到的也一樣：整包原始輸入
     * 直接丟進去時，一筆塞滿深層巢狀 `data` 的 500 KB 請求實測要驗 1–3 秒。
     * 規則只需要知道「這個欄位是不是陣列」，空陣列就足以回答。
     */
    private function shallow(mixed $value): mixed
    {
        return is_array($value) ? [] : $value;
    }

    /**
     * @param  array{fingerprint: string, session_id: string}  $payload
     */
    private function respond(array $payload, DiagnosticUploadResult $result): JsonResponse
    {
        Log::channel('api')->info('[api] diagnostics upload', [
            'outcome' => $result->outcome,
            'session_id' => $payload['session_id'],
            'fingerprint_prefix' => substr($payload['fingerprint'], 0, 8),
            'accepted' => $result->accepted,
            ...$result->context,
        ]);

        return response()->json([
            'success' => true,
            'accepted' => $result->accepted,
        ], 202);
    }

    private function validationFailed(ValidatorContract $validator): JsonResponse
    {
        return $this->error('VALIDATION_FAILED', 'Request payload is invalid', 422, [
            'details' => array_slice($validator->errors()->toArray(), 0, self::MAX_REPORTED_ERRORS, true),
        ]);
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
