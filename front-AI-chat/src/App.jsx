import { useState, useEffect, useRef, useMemo } from "react";
import "./App.css";
import { FiSettings } from "react-icons/fi";

function App() {
  const [message, setMessage] = useState("");
  const [messages, setMessages] = useState([]);
  const [loading, setLoading] = useState(false);
  const [showSettings, setShowSettings] = useState(false);
  const [thinkingEnabled, setThinkingEnabled] = useState(false);
  const [expandedThinking, setExpandedThinking] = useState(null);
  const [temperature, setTemperature] = useState(() => {
    const saved = localStorage.getItem("temperature");
    return saved ? Number(saved) : 0.7;
  });

  const messagesEndRef = useRef(null);

  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages, loading]);

  const saveSettings = () => {
    localStorage.setItem("temperature", temperature);
    setShowSettings(false);
  };

  const sendMessage = async () => {
    if (message.trim() === "") return;

    const userMessage = { role: "user", content: message };
    const conversation = [...messages, userMessage];
    setMessages(conversation);
    setMessage("");
    setLoading(true);

    const wantsThinking = thinkingEnabled; // snapshot toggle at send time

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
      console.log("API Response:", data);

      setMessages([
        ...conversation,
        {
          role: "assistant",
          content: data.reply ?? "No response received from AI.",
          thinking: data.thinking || "",
          showThinking: wantsThinking,
        },
      ]);
    } catch (error) {
      setMessages([...conversation, { role: "assistant", content: "Unable to connect to the server." }]);
    } finally {
      setLoading(false);
    }
  };

  const welcomeMessages = [
    { title: "Welcome", subtitle: "How can I help you today?" },
    { title: "Hello", subtitle: "Ask me anything." },
    { title: "Hi there", subtitle: "I'm ready whenever you are." },
    { title: "Good to see you", subtitle: "Let's start a conversation." },
    { title: "AI Chat", subtitle: "What would you like to talk about?" }
  ];

  const randomWelcome = useMemo(() => {
    return welcomeMessages[Math.floor(Math.random() * welcomeMessages.length)];
  }, []);

  return (
    <div className="app">
      <header className="header">
        <button className="settings-btn" onClick={() => setShowSettings(true)}>
          <FiSettings size={22} />
        </button>
      </header>

      <main className="messages">
        {messages.length === 0 ? (
          <div className="empty-state">
            <h2>{randomWelcome.title}</h2>
            <p>{randomWelcome.subtitle}</p>
          </div>
        ) : (
          messages.map((msg, index) => {
            console.log("Message:", msg);
            return (
              <div key={index} className={`message-row ${msg.role === "user" ? "user-row" : "ai-row"}`}>
                <div className={`message ${msg.role === "user" ? "user" : "ai"}`}>
                  {msg.role === "assistant" && msg.thinking && msg.showThinking && (
                    <>
                      <button
                        className="thinking-btn"
                        onClick={() => setExpandedThinking(expandedThinking === index ? null : index)}
                      >
                        🧠 Thinking {expandedThinking === index ? "▲" : "▼"}
                      </button>
                      {expandedThinking === index && (
                        <div className="thinking-box">{msg.thinking}</div>
                      )}
                    </>
                  )}
                  <div>{msg.content}</div>
                </div>
              </div>
            );
          })
        )}

        {loading && (
          <div className="message-row ai-row">
            <div className="message ai loading">
              <span></span><span></span><span></span>
            </div>
          </div>
        )}

        <div ref={messagesEndRef}></div>
      </main>

      <footer className="input-container">
        <div className="input-area">
          <textarea
            rows="1"
            placeholder="Message AI..."
            value={message}
            onChange={(e) => setMessage(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter" && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
              }
            }}
          />
          <button
            className={`thinking-toggle ${thinkingEnabled ? 'active' : ''}`}
            onClick={() => setThinkingEnabled(!thinkingEnabled)}
          >
            🧠 Think
          </button>
          <button onClick={sendMessage}>Send</button>
        </div>
      </footer>

      {showSettings && (
        <div className="settings-overlay" onClick={() => setShowSettings(false)}>
          <div className="settings-modal" onClick={(e) => e.stopPropagation()}>
            <h2>Settings</h2>
            <label className="temperature-label">Model Temperature</label>
            <input type="range" min="0" max="2" step="0.1" value={temperature} onChange={(e) => setTemperature(Number(e.target.value))} />
            <p className="temperature-value">{temperature.toFixed(1)}</p>
            <small className="temperature-info">
              Lower values make the AI more focused and deterministic.<br />
              Higher values make the AI more creative and diverse.
            </small>
            <div className="settings-buttons">
              <button onClick={() => setShowSettings(false)}>Cancel</button>
              <button onClick={saveSettings}>Save</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

export default App;
