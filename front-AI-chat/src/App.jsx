import { useState } from "react";
import "./App.css";

function App() {
  const [message, setMessage] = useState("");
  const [messages, setMessages] = useState([]);
  const [loading, setLoading] = useState(false);

  const [showSettings, setShowSettings] = useState(false);

  const [temperature, setTemperature] = useState(() => {
    const saved = localStorage.getItem("temperature");
    return saved ? Number(saved) : 0.7;
  });

  const saveSettings = () => {
    localStorage.setItem("temperature", temperature);
    setShowSettings(false);
  };

  const sendMessage = async () => {
    if (message.trim() === "") return;

    const userMessage = {
      role: "user",
      content: message,
    };

    const conversation = [...messages, userMessage];

    setMessages(conversation);

    setMessage("");

    setLoading(true);

    try {
      const response = await fetch(
        "https://ai-chatbot-backend-60lr.onrender.com/api/chat",
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
          },
          body: JSON.stringify({
            messages: conversation,
            temperature: temperature,
          }),
        }
      );

      const data = await response.json();

      setMessages([
        ...conversation,
        {
          role: "assistant",
          content: data.reply ?? "No response received from AI.",
        },
      ]);
    } catch (error) {
      setMessages([
        ...conversation,
        {
          role: "assistant",
          content: "Unable to connect to the server.",
        },
      ]);
    } finally {
      setLoading(false);
    }
  };

  return (
    <>
      <button
    className="settings-btn"
    onClick={() => {
      console.log("Settings clicked");
      setShowSettings(true);
    }}
  >
    ⚙️
  </button>

      <div className="container">
        <div className="chat-box">
          <div className="messages">
            {messages.length === 0 ? (
              <p className="empty">Start chatting with the AI...</p>
            ) : (
              messages.map((msg, index) => (
                <div
                  key={index}
                  className={msg.role === "user" ? "message user" : "message ai"}
                >
                  {msg.content}
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

            <button onClick={sendMessage}>Send</button>
          </div>
        </div>
      </div>

      {showSettings && (
        <div
          className="settings-overlay"
          onClick={() => setShowSettings(false)}
        >
          <div
            className="settings-modal"
            onClick={(e) => e.stopPropagation()}
          >
            <h2>⚙️ Settings</h2>

            <label className="temperature-label">
              Model Temperature
            </label>

            <input
              type="range"
              min="0"
              max="2"
              step="0.1"
              value={temperature}
              onChange={(e) =>
                setTemperature(Number(e.target.value))
              }
            />

            <p className="temperature-value">
              {temperature.toFixed(1)}
            </p>

            <small className="temperature-info">
              Lower values make the AI more focused and deterministic.
              <br />
              Higher values make the AI more creative and diverse.
            </small>

            <div className="settings-buttons">
              <button
                onClick={() => setShowSettings(false)}
              >
                Cancel
              </button>

              <button
                onClick={saveSettings}
              >
                Save
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

export default App;