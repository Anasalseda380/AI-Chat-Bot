import { useState } from "react";
import "./App.css";

function App() {
  const [message, setMessage] = useState("");
  const [messages, setMessages] = useState([]);
  const [loading, setLoading] = useState(false);

  const sendMessage = async () => {
  if (message.trim() === "") return;

  const userMessage = message;

  setMessages((prev) => [
    ...prev,
    {
      role: "user",
      text: userMessage,
    },
  ]);

  setMessage("");

  // Start loading
  setLoading(true);

  try {
    const response = await fetch("http://127.0.0.1:8000/api/chat", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Accept": "application/json",
      },
      body: JSON.stringify({
        message: userMessage,
      }),
    });

    const data = await response.json();

    setMessages((prev) => [
      ...prev,
      {
        role: "assistant",
        text: data.reply,
      },
    ]);

  } catch (error) {

    setMessages((prev) => [
      ...prev,
      {
        role: "assistant",
        text: "Unable to connect to the server.",
      },
    ]);

  } finally {
    // Stop loading
    setLoading(false);
  }
};

  return (
    <div className="container">
      <div className="chat-box">

            <div className="messages">
      {messages.length === 0 ? (
        <p className="empty">
          Start chatting with the AI...
        </p>
      ) : (
        messages.map((msg, index) => (
          <div
            key={index}
            className={msg.role === "user" ? "message user" : "message ai"}
          >
            {msg.text}
          </div>
        ))
      )}

  {loading && (
    <div className="message ai loading">
      <span></span>
      <span></span>
      <span></span>
    </div>
  )}
</div>

        <div className="input-area">
          <input
            type="text"
            placeholder="Type your message..."
            value={message}
            onChange={(e) => setMessage(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter") {
                sendMessage();
              }
            }}
          />

          <button onClick={sendMessage}>
            Send
          </button>
        </div>
      </div>
    </div>
  );
}

export default App;