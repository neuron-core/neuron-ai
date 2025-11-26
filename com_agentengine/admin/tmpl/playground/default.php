<?php
defined('_JEXEC') or die;
?>
<div class="container">
    <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title"><?php echo $this->item->name; ?></h5>
                </div>
                <div class="card-body" id="chat-history" style="height: 400px; overflow-y: scroll;">
                    <!-- Chat history will be appended here -->
                </div>
                <div class="card-footer">
                    <form id="chat-form">
                        <div class="input-group">
                            <input type="text" id="chat-message" class="form-control" placeholder="Type a message...">
                            <button type="submit" class="btn btn-primary">Send</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('chat-form');
    const messageInput = document.getElementById('chat-message');
    const chatHistory = document.getElementById('chat-history');

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const message = messageInput.value;
        if (message.trim() === '') {
            return;
        }

        // Add user message to chat history
        const userMessage = document.createElement('div');
        userMessage.classList.add('alert', 'alert-secondary');
        userMessage.textContent = 'You: ' + message;
        chatHistory.appendChild(userMessage);
        chatHistory.scrollTop = chatHistory.scrollHeight;

        // Clear the input
        messageInput.value = '';

        // Send message to the backend
        const url = 'index.php?option=com_agentengine&task=playground.chat&format=json&id=<?php echo $this->item->id; ?>';
        const data = {
            message: message
        };

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            // Add agent response to chat history
            const agentMessage = document.createElement('div');
            agentMessage.classList.add('alert', 'alert-primary');
            agentMessage.textContent = 'Agent: ' + data.data.response;
            chatHistory.appendChild(agentMessage);
            chatHistory.scrollTop = chatHistory.scrollHeight;
        });
    });
});
</script>
