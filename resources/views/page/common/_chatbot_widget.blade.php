<style>
    #chatbot-toggle {
        position: fixed;
        bottom: 20px;
        right: 20px;
        width: 60px;
        height: 60px;
        background-color: #007bff;
        color: white;
        border-radius: 50%;
        display: flex;
        justify-content: center;
        align-items: center;
        cursor: pointer;
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        z-index: 1001;
        transition: transform 0.2s ease-in-out;
    }
    #chatbot-toggle:hover {
        transform: scale(1.1);
    }
    #chatbot-container {
        position: fixed;
        bottom: 90px;
        right: 20px;
        width: 350px;
        height: 500px;
        border: 1px solid #ccc;
        border-radius: 10px;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
        font-family: sans-serif;
        background-color: #fff;
        z-index: 1000;
        transform: scale(0);
        transform-origin: bottom right;
        opacity: 0;
        transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
        visibility: hidden;
    }
    #chatbot-container.open {
        transform: scale(1);
        opacity: 1;
        visibility: visible;
    }
    #chatbot-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 15px;
        background-color: #007bff;
        color: white;
        font-weight: bold;
    }
    #chatbot-close {
        background: none;
        border: none;
        color: white;
        font-size: 24px;
        cursor: pointer;
        line-height: 1;
    }
    #chat-display {
        flex-grow: 1;
        padding: 10px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 10px;
        background-color: #f9f9f9;
    }
    .message {
        padding: 8px 12px;
        border-radius: 18px;
        max-width: 80%;
        word-wrap: break-word;
        line-height: 1.4;
    }
    .user-message {
        background-color: #007bff;
        color: white;
        align-self: flex-end;
    }
    .bot-message {
        background-color: #e9e9eb;
        color: #333;
        align-self: flex-start;
    }
    #chat-input-container {
        display: flex;
        padding: 10px;
        border-top: 1px solid #ccc;
        background-color: #fff;
    }
    #user-input {
        flex-grow: 1;
        border: 1px solid #ccc;
        border-radius: 20px;
        padding: 10px 15px;
        margin-right: 10px;
        font-size: 14px;
    }
    #user-input:focus {
        outline: none;
        border-color: #007bff;
    }
    #send-button {
        border: none;
        background-color: #007bff;
        color: white;
        border-radius: 50%;
        width: 40px;
        height: 40px;
        cursor: pointer;
        display: flex;
        justify-content: center;
        align-items: center;
        font-size: 18px;
    }
    #send-button:hover {
        background-color: #0056b3;
    }

    /* Typing Indicator */
    .typing-indicator {
        padding: 10px;
    }
    .typing-indicator span {
        height: 8px;
        width: 8px;
        background-color: #999;
        border-radius: 50%;
        display: inline-block;
        margin: 0 1px;
        animation: typing-dot 1.4s infinite;
        animation-fill-mode: both;
    }

    .typing-indicator span:nth-child(2) {
        animation-delay: 0.2s;
    }

    .typing-indicator span:nth-child(3) {
        animation-delay: 0.4s;
    }

    @keyframes typing-dot {
        0% { opacity: 0.2; transform: scale(0.8); }
        20% { opacity: 1; transform: scale(1); }
        100% { opacity: 0.2; transform: scale(0.8); }
    }
</style>

<div id="chatbot-toggle">
    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-chat-dots-fill" viewBox="0 0 16 16">
        <path d="M16 8c0 3.866-3.582 7-8 7a9.06 9.06 0 0 1-2.347-.306c-.584.296-1.925.864-4.181 1.234-.2.032-.352-.176-.273-.362.354-.836.674-1.95.77-2.966C.744 11.37 0 9.76 0 8c0-3.866 3.582-7 8-7s8 3.134 8 7zM5 8a1 1 0 1 0-2 0 1 1 0 0 0 2 0zm4 0a1 1 0 1 0-2 0 1 1 0 0 0 2 0zm3 1a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"/>
    </svg>
</div>

<div id="chatbot-container">
    <div id="chatbot-header">
        <span>Chat With Gemini</span>
        <button id="chatbot-close">&times;</button>
    </div>
    <div id="chat-display">
        <div class="message bot-message">Hello! How can I help you today?</div>
    </div>
    <div id="chat-input-container">
        <input type="text" id="user-input" placeholder="Type your message...">
        <button id="send-button" aria-label="Send Message">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-send" viewBox="0 0 16 16">
                <path d="M15.854.146a.5.5 0 0 1 .11.54l-5.819 14.547a.75.75 0 0 1-1.329.124l-3.178-4.995L.643 7.184a.75.75 0 0 1 .124-1.33L15.314.037a.5.5 0 0 1 .54.11ZM6.636 10.07l2.761 4.338L14.13 2.576 6.636 10.07Zm6.787-8.201L1.591 6.602l4.339 2.76 7.494-7.493Z"/>
            </svg>
        </button>
    </div>
</div>

<script>
    // IMPORTANT: Replace '''YOUR_GEMINI_API_KEY''' with your actual Gemini API key.
    const GEMINI_API_KEY = 'AIzaSyBbCVCvbqxrrguNcbCw5pvaLjBthN1FvBI';

    const chatbotToggle = document.getElementById('chatbot-toggle');
    const chatbotContainer = document.getElementById('chatbot-container');
    const chatbotClose = document.getElementById('chatbot-close');
    const chatDisplay = document.getElementById('chat-display');
    const userInput = document.getElementById('user-input');
    const sendButton = document.getElementById('send-button');

    chatbotToggle.addEventListener('click', () => {
        chatbotContainer.classList.toggle('open');
    });

    chatbotClose.addEventListener('click', () => {
        chatbotContainer.classList.remove('open');
    });

    sendButton.addEventListener('click', sendMessage);
    userInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    async function sendMessage() {
        const message = userInput.value.trim();
        if (message === '') return;

        appendMessage(message, 'user-message');
        userInput.value = '';

        try {
            appendTypingIndicator();
            const response = await getGeminiResponse(message);
            removeTypingIndicator();
            appendMessage(response, 'bot-message');
        } catch (error) {
            removeTypingIndicator();
            console.error('Error getting Gemini response:', error);
            appendMessage('Oops! Something went wrong. Please try again later.', 'bot-message');
        }
    }

    function appendMessage(text, className) {
        const messageElement = document.createElement('div');
        messageElement.classList.add('message', className);
        // This is a simple way to render newlines, but for security,
        // in a real app, you should sanitize this to prevent XSS.
        messageElement.innerHTML = text.replace(/\n/g, '<br>');
        chatDisplay.appendChild(messageElement);
        chatDisplay.scrollTop = chatDisplay.scrollHeight;
    }

    function appendTypingIndicator() {
        const typingElement = document.createElement('div');
        typingElement.classList.add('message', 'bot-message', 'typing-indicator');
        typingElement.innerHTML = '<span></span><span></span><span></span>';
        chatDisplay.appendChild(typingElement);
        chatDisplay.scrollTop = chatDisplay.scrollHeight;
    }

    function removeTypingIndicator() {
        const typingIndicator = document.querySelector('.typing-indicator');
        if (typingIndicator) {
            typingIndicator.remove();
        }
    }

    async function getGeminiResponse(userMessage) {
        // Do not send empty messages to the API
        if (!userMessage) {
            return "Please type a message.";
        }

        const url = `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=${GEMINI_API_KEY}`;

        const data = {
            contents: [{
                parts: [{ text: userMessage }]
            }]
        };

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            });

            if (!response.ok) {
                const errorData = await response.json();
                console.error("API Error Details:", errorData);
                throw new Error(`API error: ${response.status} - ${errorData.error.message}`);
            }

            const json = await response.json();

            if (json.candidates && json.candidates.length > 0 && json.candidates[0].content && json.candidates[0].content.parts && json.candidates[0].content.parts.length > 0) {
                return json.candidates[0].content.parts[0].text;
            } else {
                // Log the response if it's not in the expected format
                console.warn("Unexpected API response format:", json);
                return "I'm sorry, I received an unexpected response. Please try again.";
            }
        } catch (error) {
            console.error("Fetch or JSON parsing error:", error);
            // Re-throw the error to be caught by the sendMessage function's catch block
            throw error;
        }
    }
</script>