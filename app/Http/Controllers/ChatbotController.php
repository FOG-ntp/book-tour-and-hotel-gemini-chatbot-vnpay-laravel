<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use App\Models\ChatMessage;
use App\Models\Tour;
use App\Models\Hotel;
use Str;

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

    //Send message
    public function sendMessages(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $userId = Auth::id();

        // --- Handle guest token (cookie) ---
        $guestToken = null;
        if (!$userId) {
            $guestToken = $request->cookie('chat_token');
            if (!$guestToken) {
                $guestToken = 'guest_' . Str::random(32);
                cookie()->queue(cookie('chat_token', $guestToken, 60*24*180));
            }
        }

        // 1) save message user to BD
        $userMsg = ChatMessage::create([
            'user_id' => $userId,
            'guest_token' => $userId ? null : $guestToken,
            'sender' => 'user',
            'message' => $request->message,
        ]);

        // 2) Prepare data for AI
        $tours = Tour::all(['id', 'name', 'price', 'duration', 'departure_location', 'description'])->map(function($tour) {
            return "Tour {$tour->name} - Giá: " . number_format($tour->price) . "đ - Thời gian: {$tour->duration} - Khởi hành từ: {$tour->departure_location}";
        })->toArray();

        $hotels = Hotel::all(['id', 'name', 'address', 'price_range', 'description'])->map(function($hotel) {
            return "Khách sạn {$hotel->name} - Địa chỉ: {$hotel->address} - Giá từ: " . number_format($hotel->price_range) . "đ";
        })->toArray();

        $tourList = implode("\n", $tours);
        $hotelList = implode("\n", $hotels);

        $prompt = "Bạn là trợ lý tư vấn du lịch chuyên nghiệp. Nhiệm vụ của bạn là tư vấn về các tour du lịch và khách sạn sau:

DANH SÁCH TOUR:
$tourList

DANH SÁCH KHÁCH SẠN:
$hotelList

Hướng dẫn trả lời:
1. Trả lời ngắn gọn, chính xác, thân thiện
2. Chỉ sử dụng thông tin có trong danh sách tour và khách sạn
3. Nếu khách hỏi về giá, hãy nêu rõ giá tiền kèm thời gian của tour
4. Nếu khách quan tâm tour cụ thể, hãy giới thiệu cả khách sạn phù hợp trong khu vực
5. Nếu không có thông tin để trả lời, hãy đề nghị khách liên hệ trực tiếp với công ty
6. Không được tự ý thêm thông tin không có trong danh sách

Hãy tư vấn theo yêu cầu của khách:";
        
        //Get history lasted (Exp: 6 msg ~ 3 turns user-bot)
        $history = ChatMessage::query()->where(function($q) use ($userId, $guestToken) {
            if ($userId) {
                $q->where('user_id', $userId);
            } else {
                $q->where('guest_token', $guestToken);
            }
        })
        ->latest()
        ->limit(6)
        ->orderBy('created_at','asc')
        ->get();

        // Change history to suit with firmat Gemini
        $content = [];
        foreach ($history as $msg) {
            $content[] = [
                "role" => $msg->sender === 'user' ? "user" : "model",
                "parts" => [["text" => $msg->message]]
            ];
        }

        //Append new message of user
        $content[] = [
            "role" => "user",
            "parts" => [["text" => $request->message]]
        ];

        // 3) Call AI (Gemini) - If haven't GOOGLE_API_KEY return fallabck text
        $aiReplyText = "Xin lỗi, hiện tại AI chưa được cấu hình";
        if (env('GEMINI_API_KEY')) {
            try {
                $url_apikey = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';
                $payload = [
                    "systemInstruction" => [
                        "parts" =>[
                            ["text" => $prompt]
                        ]
                    ],
                    "contents"=> $content
                ];

                //Call API Gemini
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Google-Api-Key' => env('GEMINI_API_KEY')
                ])->post($url_apikey, $payload);

                if ($response->successful()){
                    $data = $response->json();
                    $aiReplyText = $data['candidates'][0]['content']['parts'][0]['text']??"Xin lỗi, ttôi chưa hiểu câu hỏi.";
                } else {
                    $aiReplyText = "Xin lỗi, hiện tại AI không thể xử lý.";
                    \Log::error('AI API error', ['response'=> $response->json()]);
                }
                
            } catch (\Throwable $e) {
                \Log::error('AI call error: ' . $e->getMessage());
                $aiReplyText = "Xin lỗi, hiện tại AI chưa được cấu hình";
            }
        }

        //4) save bot reply
        $botMsg = ChatMessage::create([
            'user_id' => $userId,
            'guest_token' => $userId ? null : $guestToken,
            'sender' => 'bot',
            'message' => $aiReplyText,
        ]);

        //5) Return 2 message created (client append)
        return response()->json([
            'user'=>$userMsg,
            'bot'=>$botMsg,
        ]);
    }
}
