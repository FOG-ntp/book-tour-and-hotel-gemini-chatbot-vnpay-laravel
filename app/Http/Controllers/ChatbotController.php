<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use App\Models\ChatMessage;
use App\Models\Tour;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

class ChatbotController extends Controller
{
    //Get history (user or guest)
    public function fetchMessages(Request $request)
    {
        if (Auth::check()) {
            $msgs = ChatMessage::where('user_id', Auth::id())->orderBy('created_at')->get();
        } else {
            $token = $request->cookie('chat_token');
            $msgs = $token ? ChatMessage::where('guest_token', $token)->orderBy('created_at')->get() : collect();
        }
        return response()->json($msgs);
    }

    public function sendMessages(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $userId = Auth::id();

        // Handle guest token (cookie)
        $guestToken = null;
        if (!$userId) {
            $guestToken = $request->cookie('chat_token');
            if (!$guestToken) {
                $guestToken = 'guest_' . Str::random(32);
                cookie()->queue(cookie('chat_token', $guestToken, 60 * 24 * 180));
            }
        }

        // 1) Save user message to DB
        $userMsg = ChatMessage::create([
            'user_id' => $userId,
            'guest_token' => $userId ? null : $guestToken,
            'sender' => 'user',
            'message' => $request->message,
        ]);

        // 2) Extract keywords from user message
        $userMessage = strtolower($request->message);
        $keywords = $this->extractKeywords($userMessage);

        // 3) Query DB dynamically with null handling
        $tourQuery = Tour::query();
        $hotelQuery = Hotel::query();

        foreach ($keywords as $keyword) {
            $tourQuery->orWhere('t_title', 'like', "%$keyword%")
                      ->orWhere('t_description', 'like', "%$keyword%")
                      ->orWhere('t_journeys', 'like', "%$keyword%")
                      ->orWhere('t_starting_gate', 'like', "%$keyword%");
            $hotelQuery->orWhere('h_name', 'like', "%$keyword%")
                       ->orWhere('h_address', 'like', "%$keyword%")
                       ->orWhere('h_description', 'like', "%$keyword%");
        }

        $tours = $tourQuery->latest()->limit(10)->get(['id', 't_title', 't_price_adults', 't_journeys', 't_starting_gate', 't_description']);
        $hotels = $hotelQuery->latest()->limit(10)->get(['id', 'h_name', 'h_address', 'h_price', 'h_description']);

        // Handle null in tours and hotels
        $tourList = $tours->map(function ($tour) {
            $title = $tour->t_title ?? 'Không có tên tour';
            $price = $tour->t_price_adults !== null ? number_format($tour->t_price_adults) . 'đ' : 'Liên hệ để biết giá';
            $journeys = $tour->t_journeys ?? 'Không có thông tin thời gian';
            $starting_gate = $tour->t_starting_gate ?? 'Không xác định';
            $description = $tour->t_description ?? 'Không có mô tả';
            return "Tour {$title} (ID: {$tour->id}) - Giá: {$price} - Thời gian: {$journeys} - Khởi hành từ: {$starting_gate} - Mô tả: {$description}";
        })->implode("\n");

        $hotelList = $hotels->map(function ($hotel) {
            $name = $hotel->h_name ?? 'Không có tên khách sạn';
            $address = $hotel->h_address ?? 'Không có địa chỉ';
            $price = $hotel->h_price !== null ? number_format($hotel->h_price) . 'đ' : 'Liên hệ để biết giá';
            $description = $hotel->h_description ?? 'Không có mô tả';
            return "Khách sạn {$name} (ID: {$hotel->id}) - Địa chỉ: {$address} - Giá từ: {$price} - Mô tả: {$description}";
        })->implode("\n");

        // Fallback if no data
        $tourList = $tourList ?: "Không tìm thấy tour phù hợp.";
        $hotelList = $hotelList ?: "Không tìm thấy khách sạn phù hợp.";

        // System prompt
        $prompt = "Bạn là trợ lý tư vấn du lịch chuyên nghiệp. Nhiệm vụ của bạn là tư vấn về các tour du lịch và khách sạn sau:

DANH SÁCH TOUR:
$tourList

DANH SÁCH KHÁCH SẠN:
$hotelList

Hướng dẫn trả lời:
1. Trả lời ngắn gọn, chính xác, thân thiện bằng tiếng Việt.
2. Chỉ sử dụng thông tin có trong danh sách tour và khách sạn.
3. Nếu khách hỏi về giá, hãy nêu rõ giá tiền kèm thời gian của tour.
4. Nếu khách quan tâm tour cụ thể, hãy giới thiệu cả khách sạn phù hợp trong khu vực (dựa trên địa chỉ).
5. Nếu khách muốn đặt tour, hướng dẫn truy cập website hoặc liên hệ (không xử lý đặt chỗ ở đây).
6. Nếu không có thông tin để trả lời, hãy đề nghị khách liên hệ trực tiếp với công ty.
7. Không được tự ý thêm thông tin không có trong danh sách.

Hãy tư vấn theo yêu cầu của khách: {$request->message}";

        // Get history (6 messages for context)
        $history = ChatMessage::query()->where(function ($q) use ($userId, $guestToken) {
            if ($userId) {
                $q->where('user_id', $userId);
            } else {
                $q->where('guest_token', $guestToken);
            }
        })
        ->latest()
        ->limit(6)
        ->orderBy('created_at', 'asc')
        ->get();

        // Convert history to Gemini format
        $content = [];
        foreach ($history as $msg) {
            $content[] = [
                "role" => $msg->sender === 'user' ? "user" : "model",
                "parts" => [["text" => $msg->message]]
            ];
        }

        // Append user message
        $content[] = [
            "role" => "user",
            "parts" => [["text" => $request->message]]
        ];

        // Call AI (Gemini)
        $aiReplyText = "Xin lỗi, hiện tại AI chưa được cấu hình hoặc có lỗi kết nối.";
        
        if (env('GEMINI_API_KEY')) {
            try {
                $apiKey = env('GEMINI_API_KEY');
                $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key='.$apiKey;

                $contentsWithSystemInstruction = [
                    [
                        "role" => "model",
                        "parts" => [["text" => $prompt]]
                    ]
                ];
                
                $finalContents = array_merge($contentsWithSystemInstruction, $content);

                $payload = [
                    "contents" => $finalContents,
                ];

                // Log payload for debugging
                \Log::info('Gemini API Payload', ['payload' => $payload]);

                // Call API
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                ])->post($url, $payload);

                if ($response->successful()) {
                    $data = $response->json();
                    $aiReplyText = $data['candidates'][0]['content']['parts'][0]['text'] ?? "Xin lỗi, AI đã trả về phản hồi không hợp lệ.";
                    \Log::info('Gemini API Response', ['response' => $data]);
                } else {
                    $aiReplyText = "Xin lỗi, hiện tại AI không thể xử lý. (HTTP Status: " . $response->status() . ")";
                    \Log::error('AI API error (HTTP Failed)', [
                        'status' => $response->status(),
                        'response_body' => $response->json() ?? $response->body()
                    ]);
                }
                
            } catch (Throwable $e) {
                \Log::error('AI call exception: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
                $aiReplyText = "Xin lỗi, đã xảy ra lỗi hệ thống khi gọi AI.";
            }
        }

        // Save bot reply
        $botMsg = ChatMessage::create([
            'user_id' => $userId,
            'guest_token' => $userId ? null : $guestToken,
            'sender' => 'bot',
            'message' => $aiReplyText,
        ]);

        // Return messages
        return response()->json([
            'user' => $userMsg,
            'bot' => $botMsg,
        ]);
    }

    private function extractKeywords($message)
    {
        $stopWords = ['tôi', 'muốn', 'là', 'có', 'về', 'cho', 'với', 'từ', 'đến'];
        $words = preg_split('/\s+/', $message);
        $keywords = array_filter($words, function ($word) use ($stopWords) {
            return strlen($word) > 2 && !in_array($word, $stopWords);
        });
        return array_unique($keywords);
    }
}