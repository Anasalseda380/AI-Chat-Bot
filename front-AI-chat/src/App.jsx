import { useState, useEffect, useRef, useMemo } from "react";
import "./App.css";
import { FiSettings, FiPlus, FiX, FiImage, FiVideo, FiMusic, FiSquare } from "react-icons/fi";

function App() {
  const [message, setMessage] = useState("");
  const [messages, setMessages] = useState([]);
  const [loading, setLoading] = useState(false);
  const [showSettings, setShowSettings] = useState(false);
  const [thinkingEnabled, setThinkingEnabled] = useState(false);
  const [expandedThinking, setExpandedThinking] = useState(null);
  const [showMediaMenu, setShowMediaMenu] = useState(false);
  const [attachments, setAttachments] = useState([]);
  const [pendingMediaType, setPendingMediaType] = useState(null);
  const [isStreaming, setIsStreaming] = useState(false);
  const [temperature, setTemperature] = useState(() => {
    const saved = localStorage.getItem("temperature");
    return saved ? Number(saved) : 0.7;
  });

  const messagesEndRef = useRef(null);
  const mediaMenuRef = useRef(null);
  const fileInputRef = useRef(null);
  const abortControllerRef = useRef(null);
  const currentRequestIdRef = useRef(0);

  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages, loading]);

  useEffect(() => {
    const handleClickOutside = (e) => {
      if (mediaMenuRef.current && !mediaMenuRef.current.contains(e.target)) {
        setShowMediaMenu(false);
      }
    };
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  const saveSettings = () => {
    localStorage.setItem("temperature", temperature);
    setShowSettings(false);
  };

  const acceptFor = (kind) => {
    if (kind === "image") return "image/*";
    if (kind === "video") return "video/*";
    if (kind === "audio") return "audio/*";
    return "*/*";
  };

  const openFilePicker = (kind) => {
    setPendingMediaType(kind);
    setShowMediaMenu(false);
    setTimeout(() => fileInputRef.current?.click(), 0);
  };

  const handleFileChange = (e) => {
    const file = e.target.files[0];
    if (!file || !pendingMediaType) return;

    const reader = new FileReader();
    reader.onload = () => {
      setAttachments((prev) => [
        ...prev,
        {
          id: Date.now() + Math.random(),
          kind: pendingMediaType,
          name: file.name,
          dataUrl: reader.result,
        },
      ]);
    };
    reader.readAsDataURL(file);
    e.target.value = "";
  };

  const removeAttachment = (id) => {
    setAttachments((prev) => prev.filter((a) => a.id !== id));
  };

  const buildUserContent = () => {
    if (attachments.length === 0) return message;

    const parts = [];
    if (message.trim() !== "") {
      parts.push({ type: "text", text: message });
    }
    attachments.forEach((a) => {
      if (a.kind === "image") {
        parts.push({ type: "image_url", image_url: { url: a.dataUrl } });
      } else if (a.kind === "video") {
        parts.push({ type: "video_url", video_url: { url: a.dataUrl } });
      } else if (a.kind === "audio") {
        const base64 = a.dataUrl.split(",")[1];
        const format = a.name.split(".").pop().toLowerCase();
        parts.push({ type: "input_audio", input_audio: { data: base64, format } });
      }
    });
    return parts;
  };

  const stopResponse = () => {
  // Invalidate the current request
  currentRequestIdRef.current++;

  if (abortControllerRef.current) {
    abortControllerRef.current.abort();
    abortControllerRef.current = null;
  }

  setIsStreaming(false);
  setLoading(false);

  // Mark the last assistant message as finished
  setMessages((prev) => {
    if (prev.length === 0) return prev;

    const updated = [...prev];
    const last = updated[updated.length - 1];

    if (last.role === "assistant" && last.streaming) {
      updated[updated.length - 1] = {
        ...last,
        streaming: false,
      };
    }

    return updated;
  });
};

  const sendMessage = async () => {
    if (message.trim() === "" && attachments.length === 0) return;
    if (isStreaming) return;

    // Increment request ID to track this specific request
    currentRequestIdRef.current += 1;
    const currentRequestId = currentRequestIdRef.current;

    const userMessage = {
      role: "user",
      content: buildUserContent(),
      display: message,
      attachmentsPreview: attachments,
    };

    const conversation = [...messages, userMessage];
    const wantsThinking = thinkingEnabled;
    const assistantIndex = conversation.length;

    setMessages([
      ...conversation,
      { role: "assistant", content: "", display: "", thinking: "", showThinking: wantsThinking, streaming: true },
    ]);
    setMessage("");
    setAttachments([]);
    setLoading(true);
    setIsStreaming(true);

    const controller = new AbortController();
    abortControllerRef.current = controller;

    const apiMessages = conversation.map((m) => ({ role: m.role, content: m.content }));

    try {
      const response = await fetch("https://ai-chatbot-backend-60lr.onrender.com/api/chat", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "text/event-stream",
        },
        body: JSON.stringify({ messages: apiMessages, temperature }),
        signal: controller.signal,
      });
      
      if (controller.signal.aborted) {
        return;
      }

      // Check if this request was superseded
      if (currentRequestId !== currentRequestIdRef.current) {
        controller.abort();
        return;
      }

      if (!response.body) throw new Error("No stream body");

      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let buffer = "";
      let accumulatedContent = "";
      let accumulatedThinking = "";

      setLoading(false);

      while (true) {
        // Check if this request was superseded
        if (currentRequestId !== currentRequestIdRef.current) {
          controller.abort();
          break;
        }

        const { done, value } = await reader.read();
        if (done) break;

        // Check if this request was superseded
        if (currentRequestId !== currentRequestIdRef.current) {
          controller.abort();
          break;
        }

        buffer += decoder.decode(value, { stream: true });
        const chunks = buffer.split("\n\n");
        buffer = chunks.pop();

        for (const chunk of chunks) {
          // Check if this request was superseded
          if (currentRequestId !== currentRequestIdRef.current) {
            controller.abort();
            break;
          }

          const trimmed = chunk.trim();
          if (!trimmed.startsWith("data: ")) continue;
          const payload = trimmed.slice(6);
          if (payload === "[DONE]") continue;

          try {
            const parsed = JSON.parse(payload);
            const delta = parsed.choices?.[0]?.delta;

            if (delta?.content) accumulatedContent += delta.content;
            if (delta?.reasoning) accumulatedThinking += delta.reasoning;

            // Only update if this is still the current request
            if (currentRequestId === currentRequestIdRef.current) {
              setMessages((prev) => {
                const updated = [...prev];
                updated[assistantIndex] = {
                  ...updated[assistantIndex],
                  content: accumulatedContent,
                  display: accumulatedContent,
                  thinking: accumulatedThinking,
                };
                return updated;
              });
            }
          } catch (err) {
            // skip malformed/partial JSON chunk
          }
        }
      }

      // Only update if this is still the current request
      if (currentRequestId === currentRequestIdRef.current && !controller.signal.aborted) {
        setMessages((prev) => {
          const updated = [...prev];
          updated[assistantIndex] = { ...updated[assistantIndex], streaming: false };
          return updated;
        });
      }
    } catch (error) {
        if (error.name === "AbortError") {
          return;
        }

        if (currentRequestId !== currentRequestIdRef.current) {
          return;
        }

        setMessages((prev) => {
          const updated = [...prev];
          updated[assistantIndex] = {
            role: "assistant",
            content: "Unable to connect to the server.",
            display: "Unable to connect to the server.",
          };
          return updated;
        });
      } finally {
      if (currentRequestId === currentRequestIdRef.current) {
        setLoading(false);
        setIsStreaming(false);
      }

      if (abortControllerRef.current === controller) {
        abortControllerRef.current = null;
      }
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

                  {msg.attachmentsPreview && msg.attachmentsPreview.length > 0 && (
                    <div className="attachment-preview-row">
                      {msg.attachmentsPreview.map((a) => (
                        <div key={a.id} className="attachment-chip sent">
                          {a.kind === "image" && <FiImage size={14} />}
                          {a.kind === "video" && <FiVideo size={14} />}
                          {a.kind === "audio" && <FiMusic size={14} />}
                          <span>{a.name}</span>
                        </div>
                      ))}
                    </div>
                  )}

                  <div>{msg.display}</div>
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
        {attachments.length > 0 && (
          <div className="attachment-preview-row pending">
            {attachments.map((a) => (
              <div key={a.id} className="attachment-chip">
                {a.kind === "image" && <FiImage size={14} />}
                {a.kind === "video" && <FiVideo size={14} />}
                {a.kind === "audio" && <FiMusic size={14} />}
                <span>{a.name}</span>
                <button onClick={() => removeAttachment(a.id)} type="button">
                  <FiX size={14} />
                </button>
              </div>
            ))}
          </div>
        )}

        <div className="input-area">
          <div className="media-menu-wrapper" ref={mediaMenuRef}>
            <button
              className="media-plus-btn"
              onClick={() => setShowMediaMenu(!showMediaMenu)}
              type="button"
            >
              <FiPlus size={20} />
            </button>

            {showMediaMenu && (
              <div className="media-menu">
                <p className="media-menu-title">Attach media</p>
                <ul>
                  <li onClick={() => openFilePicker("image")}>
                    <FiImage size={15} /> Image (jpg, png, etc.)
                  </li>
                  <li onClick={() => openFilePicker("video")}>
                    <FiVideo size={15} /> Video
                  </li>
                  <li onClick={() => openFilePicker("audio")}>
                    <FiMusic size={15} /> Audio
                  </li>
                </ul>
              </div>
            )}
          </div>

          <input
            type="file"
            ref={fileInputRef}
            style={{ display: "none" }}
            accept={acceptFor(pendingMediaType)}
            onChange={handleFileChange}
          />

          <div className="textarea-wrapper">
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
            {isStreaming && (
              <button className="stop-btn" onClick={stopResponse} type="button">
                <FiSquare size={14} />
              </button>
            )}
          </div>

          <button
            className={`thinking-toggle ${thinkingEnabled ? 'active' : ''}`}
            onClick={() => setThinkingEnabled(!thinkingEnabled)}
          >
            🧠 Think
          </button>
          <button className="send-btn" onClick={sendMessage}>Send</button>
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