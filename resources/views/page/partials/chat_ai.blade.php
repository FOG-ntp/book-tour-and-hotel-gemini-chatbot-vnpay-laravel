<style>
        #chat-widget {
            position: fixed;
            z-index: 999999 !important; 
            bottom: 20px; 
            right: 2% !important; 
            left: auto !important; 
            font-family: 'Inter', Arial, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }

        #chat-toggle {
            background: #f15d30; 
            color: white;
            width: 55px;
            height: 55px;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            font-size: 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3); 
            transition: transform 0.2s;
        }
        
        #chat-toggle:hover {
            transform: scale(1.05);
        }

        #chat-box {
            width: 300px;
            height: 400px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            margin-bottom: 15px; 
            transform-origin: bottom right;
            transition: all 0.3s ease-in-out;
            opacity: 1;
            transform: scale(1);
        }
        .hidden {
            opacity: 0 !important;
            transform: scale(0.8) !important;
            height: 0 !important;
            padding: 0 !important;
            margin: 0 !important;
            pointer-events: none;
        }
        #chat-header {
            background: #f15d30;
            color: white;
            padding: 10px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: bold;
        }

        #chat-close {
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
            line-height: 1;
            padding: 0;
            margin: 0;
            opacity: 0.8;
            transition: opacity 0.2s;
        }
        #chat-close:hover {
            opacity: 1;
        }
        #chat-messages {
            flex: 1;
            padding: 10px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }
        
        #chat-messages::-webkit-scrollbar {
            width: 8px;
        }
        #chat-messages::-webkit-scrollbar-thumb {
            background-color: #f15d30;
            border-radius: 4px;
        }
        #chat-input {
            display: flex;
            align-items: center;
            padding: 10px;
            border-top: 1px solid #eee;
            background: #f9f9f9;
        }
        .bot-msg, .user-msg {
            padding: 8px 12px;
            border-radius: 15px; /* Bo góc mềm mại hơn */
            margin-bottom: 8px;
            max-width: 85%;
            font-size: 14px;
        }
        .bot-msg {
            background: #e6e6e6; /* Màu sáng hơn cho bot */
            color: #333;
            align-self: flex-start;
            border-top-left-radius: 3px;
        }
        .user-msg {
            background: #f15d30; /* Màu thương hiệu cho người dùng */
            color: white;
            align-self: flex-end;
            border-bottom-right-radius: 3px;
        }
        .bot-msg,
        .user-msg {
            word-wrap: break-word;
            overflow-wrap: break-word;
            white-space: pre-wrap;
        }
        
        #message-input {
            flex: 1;
            border: 1px solid #ccc;
            border-radius: 20px; /* Bo góc input */
            padding: 8px 12px;
            margin-right: 8px;
            outline: none;
            transition: border-color 0.2s;
        }
        
        #message-input:focus {
            border-color: #f15d30;
        }
        #send-btn {
            background: #f15d30;
            color: white;
            border: none;
            border-radius: 20px;
            padding: 8px 15px;
            cursor: pointer;
            font-weight: bold;
            transition: background 0.2s;
        }
        
        #send-btn:hover {
            background: #d44926;
        }
    </style>
</head>
<body>

<div id="chat-widget" >
    <!-- Nút bật/tắt chatbot -->
    <div id="chat-toggle">💬</div>

    <!-- Hộp chat (Ban đầu ẩn) -->
    <div id="chat-box" class="hidden">
        <div id="chat-header">
            <span>Hỗ trợ trực tuyến</span>
            <button id="chat-close" title="Đóng">&#9587;</button>
        </div>
        <div id="chat-messages"></div>
        <div id="chat-input">
            <input type="text" id="message-input" placeholder="Nhập tin nhắn...">
            <button id="send-btn">Gửi</button>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        // Xử lý bật/tắt hộp chat
        $("#chat-toggle").click(function() {
            const chatBox = $("#chat-box");
            chatBox.toggleClass("hidden");
            
            if (!chatBox.hasClass("hidden")) {
                $("#chat-toggle").hide(); 
                loadMessages();

            } else {
                $("#chat-toggle").show();
            }
        });

        // Xử lý đóng hộp chat
        $("#chat-close").click(function() {
            $("#chat-box").addClass("hidden");
            // Khi đóng bằng nút X -> Hiện nút toggle
            $("#chat-toggle").show(); 
        });

        // Xử lý gửi tin nhắn
        $("#send-btn").click(function() {
            let mgs = $("#message-input").val().trim();
            if (!mgs) return; 
            console.log('message send:',mgs);
            /* $.ajaxSetup({}); */

            // Giả lập gọi API để gửi tin nhắn
            $.post('/chat/send', { message: mgs }, function(res){
                if (res.user) appendOne(res.user);
                if (res.bot) appendOne(res.bot);
                $("#message-input").val('');
            }).fail(function(){
                appendOne({ sender: 'bot', message: 'Lỗi: không gửi được tin nhắn.'});
            });
        });

        // Enter để gửi tin nhắn
        $("#message-input").keypress(function(e){
            if (e.which === 13){
                e.preventDefault(); // Ngăn chặn việc xuống dòng mặc định
                $("#send-btn").click();
                return false;
            }
        });

        // Hàm escapeHtml để bảo mật nội dung tin nhắn, tránh XSS
        function escapeHtml(text) {
            return $('<div/>').text(text).html()
        }

    // Hàm thêm tin nhắn vào hộp thoại
        function appendOne(m){
            let cls = m.sender === 'user' ? 'user-msg' : 'bot-msg';
            const messagesContainer = $("#chat-messages");
            messagesContainer.append(`<div class="${cls}">${escapeHtml(m.message)}</div>`);
            messagesContainer.scrollTop(messagesContainer[0].scrollHeight); // Cuộn xuống cuối
        }
    
    // Hàm tải lịch sử tin nhắn
        function loadMessages()
        {
            $("#chat-messages").html('');
            $.get('/chat/messages', function(msgs){

            if (!msgs || msgs.length === 0) {
                $("#chat-messages").append(`<div class="bot-msg">Xin chào 👍!, tôi có thể giúp gì cho bạn ?</div>`);
                return;
            }
            console.log('messages: ', msgs);

            msgs.forEach(function(m){appendOne(m); });
            $('#chat-messages').scrollTop($('#chat-messages')[0].scrollHeight);
            });
        }
    });
</script>