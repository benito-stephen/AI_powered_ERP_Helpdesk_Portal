
<?php
session_start();
include('db.php');

if (!isset($_SESSION['userid'])) {
    echo "Please log in first.";
    exit();
}

$userid = $_SESSION['userid'];

$username = isset($_SESSION['name']) ? $_SESSION['name'] : 'Employee';
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'employee';

if ($role == "Hr_admin") {
    $welcome = "I can assist you with employee management and HR policies.";
} elseif ($role == "Technical_admin") {
    $welcome = "I can assist you with technical support and system management.";
} else {
    $welcome = "I can assist you with attendance, leaves and company policies.";
}

if (isset($_POST['clear_chat'])) {

    $stmt_clear = mysqli_prepare($conn,
        "DELETE FROM chat_memory WHERE userid=?");

    mysqli_stmt_bind_param($stmt_clear, "s", $userid);
    mysqli_stmt_execute($stmt_clear);
    mysqli_stmt_close($stmt_clear);

    header("Location: chat.php");
    exit();
}

$history_query =
    "SELECT sender, message, timestamp
     FROM chat_memory
     WHERE userid=?
     ORDER BY timestamp ASC";

$stmt_history = mysqli_prepare($conn, $history_query);
mysqli_stmt_bind_param($stmt_history, "s", $userid);
mysqli_stmt_execute($stmt_history);
$history_res = mysqli_stmt_get_result($stmt_history);
?>

<!DOCTYPE html>
<html>
<head>
    <title>AI Helpdesk Assistant</title>

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <style>

        body{
            font-family:'Segoe UI',sans-serif;
            background:#f4f4f9;
            margin:0;
            height:100vh;
            display:flex;
            flex-direction:column;
        }

        .chat-header{
            background:linear-gradient(135deg,#5f2397,#8e44ad);
            color:white;
            padding:15px 20px;
            display:flex;
            justify-content:space-between;
            align-items:center;
        }

        .header-buttons{
            display:flex;
            gap:10px;
        }

        .btn{
            background:rgba(255,255,255,0.2);
            color:white;
            border:none;
            padding:8px 12px;
            border-radius:5px;
            cursor:pointer;
            text-decoration:none;
        }

        .btn:hover{
            background:rgba(255,255,255,0.4);
        }

        .chat-container{
            flex:1;
            overflow-y:auto;
            padding:20px;
            display:flex;
            flex-direction:column;
            gap:12px;
        }

        .message{
            max-width:80%;
            padding:12px 15px;
            border-radius:12px;
            word-wrap:break-word;
        }

        .user{
            align-self:flex-end;
            background:#8e44ad;
            color:white;
        }

        .ai{
            align-self:flex-start;
            background:white;
            border:1px solid #ddd;
        }

        .msg-time{
            margin-top:8px;
            font-size:11px;
            opacity:0.7;
            text-align:right;
        }

        .typing-indicator{
            display:none;
            background:white;
            padding:10px;
            border-radius:10px;
            width:fit-content;
            border:1px solid #ddd;
        }

        .suggestions{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            margin-bottom:15px;
        }

        .suggestions button{
            background:#e9d8f5;
            border:none;
            padding:8px 12px;
            border-radius:20px;
            cursor:pointer;
        }

        .chat-input-area{
            background:white;
            padding:15px;
            border-top:1px solid #ddd;
        }

        .input-wrapper{
            display:flex;
            gap:10px;
        }

        input{
            flex:1;
            padding:12px;
            border-radius:8px;
            border:1px solid #ccc;
            outline:none;
        }

        #sendBtn{
            background:#8e44ad;
            color:white;
            border:none;
            border-radius:8px;
            padding:12px 20px;
            cursor:pointer;
        }

        #sendBtn:disabled{
            background:gray;
            cursor:not-allowed;
        }

        .counter{
            font-size:12px;
            color:gray;
            margin-top:5px;
            text-align:right;
        }

    </style>
</head>

<body>

<div class="chat-header">

    <h2>AI Helpdesk -
        <?php echo htmlspecialchars($username); ?>
    </h2>

    <div class="header-buttons">

        <form method="POST">
            <button class="btn"
                    name="clear_chat">
                    Clear History
            </button>
        </form>

        <a href="logout.php" class="btn">
            Logout
        </a>

    </div>
</div>

<div class="chat-container" id="chatContainer">

<?php if(mysqli_num_rows($history_res) == 0): ?>

    <div class="message ai">
        Hello <b><?php echo htmlspecialchars($username); ?></b>
        <br><br>
        <?php echo htmlspecialchars($welcome); ?>
    </div>

    <div class="suggestions">
        <button onclick="askQuestion('What is my leave balance?')">
            Leave Balance
        </button>

        <button onclick="askQuestion('Explain attendance policy')">
            Attendance Policy
        </button>

        <button onclick="askQuestion('How to apply for leave?')">
            Apply Leave
        </button>
    </div>

<?php else: ?>

    <?php while($chat = mysqli_fetch_assoc($history_res)): ?>

        <div class="message <?php echo ($chat['sender']=='user') ? 'user':'ai'; ?>">

            <?php echo nl2br(htmlspecialchars($chat['message'])); ?>

            <div class="msg-time">
                <?php echo date("d M Y h:i A",
                    strtotime($chat['timestamp'])); ?>
            </div>

        </div>

    <?php endwhile; ?>

<?php endif; ?>

<div class="typing-indicator"
     id="typingIndicator">
     AI is typing...
</div>

</div>

<div class="chat-input-area">

    <div class="input-wrapper">

        <input
            type="text"
            id="userInput"
            maxlength="250"
            placeholder="Type your message..."
            onkeypress="handleKeyPress(event)"
            oninput="updateCounter()">

        <button id="sendBtn"
                onclick="sendMessage()">
            Send
        </button>

    </div>

    <div class="counter">
        <span id="counter">0</span>/250
    </div>

</div>

<script>

const chatContainer = document.getElementById('chatContainer');
const userInput = document.getElementById('userInput');
const typingIndicator = document.getElementById('typingIndicator');
const sendBtn = document.getElementById('sendBtn');

let isSending = false;

chatContainer.scrollTop = chatContainer.scrollHeight;

function updateCounter() {
    document.getElementById('counter').innerText =
        userInput.value.length;
}

function askQuestion(question) {

    if (isSending) return;

    userInput.value = question;
    updateCounter();
    sendMessage();
}

function handleKeyPress(event) {

    if (event.key === 'Enter' && !event.shiftKey) {

        event.preventDefault();

        if (!isSending) {
            sendMessage();
        }
    }
}

async function sendMessage() {

    if (isSending) return;

    let text = userInput.value.trim();

    if (text === '') return;

    if (text.length > 250) {
        alert("Message cannot exceed 250 characters.");
        return;
    }

    isSending = true;

    appendMessage(text, 'user');

    userInput.value = '';
    updateCounter();

    sendBtn.disabled = true;
    userInput.disabled = true;

    sendBtn.innerText = "Sending...";
    typingIndicator.style.display = 'block';

    try {

        await new Promise(resolve =>
            setTimeout(resolve, 1000));

        const response =
            await fetch('chatbot_backend.php', {

            method: 'POST',

            headers: {
                'Content-Type': 'application/json'
            },

            body: JSON.stringify({
                message: text
            })

        });

        if (!response.ok) {
            throw new Error('HTTP Error');
        }

        const data = await response.json();

        typingIndicator.style.display = 'none';

        if (data.response) {
            appendMessage(data.response, 'ai');
        }
        else if (data.reply) {
            appendMessage(data.reply, 'ai');
        }
        else if (data.error) {
            appendMessage("⚠️ " + data.error, 'ai');
        }
        else {
            appendMessage(
                "⚠️ Unable to generate response.",
                'ai'
            );
        }

    }
    catch(error){

        console.error(error);

        typingIndicator.style.display = 'none';

        appendMessage(
            "⚠️ AI service is currently unavailable. Please try again shortly.",
            'ai'
        );
    }
    finally{

        isSending = false;

        sendBtn.disabled = false;
        userInput.disabled = false;

        sendBtn.innerText = "Send";
        userInput.focus();
    }
}

function appendMessage(text, sender){

    let div = document.createElement('div');
    div.classList.add('message', sender);

    let msgContent = document.createElement('div');

    const escaped = text
        .replace(/&/g,"&amp;")
        .replace(/</g,"&lt;")
        .replace(/>/g,"&gt;")
        .replace(/\n/g,"<br>");

    msgContent.innerHTML = escaped;

    let timeDiv = document.createElement('div');
    timeDiv.classList.add('msg-time');

    let now = new Date();

    timeDiv.innerText =
        now.toLocaleDateString() + ' ' +
        now.toLocaleTimeString([], {
            hour:'2-digit',
            minute:'2-digit'
        });

    div.appendChild(msgContent);
    div.appendChild(timeDiv);

    chatContainer.insertBefore(
        div,
        typingIndicator
    );

    chatContainer.scrollTop =
        chatContainer.scrollHeight;
}

</script>

</body>
</html>

<?php
mysqli_stmt_close($stmt_history);
mysqli_close($conn);
?>

