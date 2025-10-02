<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Hotel;
use App\Models\Tour;
use App\Models\Location;
use Carbon\Carbon;
use Exception;

class GeminiConsultationService
{
    protected string $apiKey;
    protected string $model = 'gemini-1.5-flash'; // Sử dụng model mới hơn

    /**
     * Khởi tạo service, kiểm tra API key.
     * @throws Exception
     */
    public function __construct()
    {
        $this->apiKey = env('GEMINI_API_KEY');
        if (empty($this->apiKey)) {
            // Ghi log và ném ra ngoại lệ nếu thiếu key
            Log::error('GEMINI_API_KEY is not configured in .env file.');
            throw new Exception('API Key cho dịch vụ AI chưa được cấu hình.');
        }
    }

    /**
     * Phương thức chính để nhận tư vấn.
     *
     * @param string $userQuery
     * @return string
     * @throws Exception
     */
    public function getConsultation(string $userQuery): string
    {
        try {
            // Lấy ngữ cảnh từ database
            $dbContext = $this->getPrivateDatabaseContext($userQuery);

            // Tạo system instruction và prompt hoàn chỉnh
            $systemInstruction = "Bạn là một chuyên gia tư vấn du lịch và khách sạn của công ty chúng tôi. " .
                "Nhiệm vụ của bạn là trả lời câu hỏi của người dùng dựa trên 'NGỮ CẢNH DATABASE' được cung cấp. " .
                "Hãy luôn trả lời một cách thân thiện, chính xác, chuyên nghiệp và không bịa đặt thông tin. " .
                "Nếu không tìm thấy thông tin trong ngữ cảnh, hãy lịch sự thông báo rằng bạn không có dữ liệu về vấn đề đó.";
            
            $fullPrompt = "NGỮ CẢNH DATABASE:\n" . $dbContext . "\n\n" .
                          "YÊU CẦU CỦA NGƯỜI DÙNG: " . $userQuery;

            // Gọi API
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

            $response = Http::post($url, [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [['text' => $fullPrompt]]
                    ]
                ],
                'system_instruction' => [
                    'role' => 'model',
                    'parts' => [['text' => $systemInstruction]]
                ]
            ]);

            // Xử lý kết quả
            if ($response->successful()) {
                return $response->json()['candidates'][0]['content']['parts'][0]['text'] ?? 'Rất tiếc, tôi không thể xử lý yêu cầu của bạn lúc này.';
            }

            // Xử lý lỗi từ API
            $errorDetails = $response->json()['error']['message'] ?? 'Lỗi không xác định từ API.';
            Log::error('Gemini API Error: ' . $errorDetails, $response->json());
            throw new Exception("Lỗi khi giao tiếp với dịch vụ AI: " . $errorDetails);

        } catch (Exception $e) {
            // Bắt và ném lại ngoại lệ để Controller xử lý
            Log::error('GeminiConsultationService Exception: ' . $e->getMessage());
            throw $e; // Ném lại để controller có thể bắt và trả về lỗi 500
        }
    }

    /**
     * Lấy dữ liệu từ database (Tour, Hotel) để làm ngữ cảnh cho AI.
     *
     * @param string $query
     * @return string
     */
    private function getPrivateDatabaseContext(string $query): string
    {
        $locationId = null;
        $locations = Location::active()->get();
        foreach ($locations as $location) {
            if (stripos($query, $location->l_name) !== false) {
                $locationId = $location->id;
                break;
            }
        }

        $today = Carbon::now()->toDateString();

        // Lấy 5 khách sạn mới nhất, ưu tiên theo địa điểm
        $hotelQuery = Hotel::active()->with('location')->orderByDesc('id')->limit(5);
        if ($locationId) {
            $hotelQuery->where('h_location_id', $locationId);
        }
        $hotels = $hotelQuery->get();

        // Lấy 5 tour sắp diễn ra, ưu tiên theo địa điểm
        $tourQuery = Tour::where('t_status', 1)
                         ->where('t_end_date', '>=', $today)
                         ->with('location')
                         ->orderBy('t_start_date')
                         ->limit(5);
        if ($locationId) {
            $tourQuery->where('t_location_id', $locationId);
        }
        $tours = $tourQuery->get();

        // Định dạng dữ liệu thành JSON để đưa vào prompt
        $contextData = [
            'hotels' => $hotels->map(fn($hotel) => [
                'name' => $hotel->h_name,
                'location' => $hotel->location->l_name ?? 'N/A',
                'price_after_sale' => number_format($hotel->h_price - ($hotel->h_price * $hotel->h_sale / 100)) . ' VNĐ/đêm',
                'summary' => substr(strip_tags($hotel->h_description), 0, 100) . '...'
            ])->toArray(),
            'tours' => $tours->map(fn($tour) => [
                'title' => $tour->t_title,
                'location' => $tour->location->l_name ?? 'N/A',
                'price_after_sale' => number_format($tour->t_price_adults - ($tour->t_price_adults * $tour->t_sale / 100)) . ' VNĐ/người',
                'start_date' => Carbon::parse($tour->t_start_date)->format('d/m/Y'),
                'available_slots' => ($tour->t_number_guests - $tour->t_number_registered)
            ])->toArray(),
        ];

        return json_encode($contextData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}