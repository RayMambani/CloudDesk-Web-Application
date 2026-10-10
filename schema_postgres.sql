-- CloudDesk Web — PostgreSQL schema (Render). Optional: the site creates it automatically on first visit.

CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(191) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    department VARCHAR(100) NOT NULL DEFAULT 'General',
    role VARCHAR(20) NOT NULL DEFAULT 'user',
    phone VARCHAR(30) NULL,
    email_notifications SMALLINT NOT NULL DEFAULT 1,
    sms_notifications SMALLINT NOT NULL DEFAULT 0,
    active SMALLINT NOT NULL DEFAULT 1,
    security_question VARCHAR(30) NULL,
    security_answer_hash VARCHAR(255) NULL,
    theme VARCHAR(10) NOT NULL DEFAULT 'dark',
    language VARCHAR(10) NOT NULL DEFAULT 'en',
    created_at TIMESTAMP NOT NULL
    );

CREATE TABLE IF NOT EXISTS tickets (
    id VARCHAR(64) PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    priority VARCHAR(20) NOT NULL DEFAULT 'medium',
    category VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL,
    user_id INT NOT NULL,
    assigned_to VARCHAR(150) NULL,
    attachment_path VARCHAR(255) NULL,
    attachment_name VARCHAR(255) NULL,
    resolved_at TIMESTAMP NULL,
    rating SMALLINT NULL,
    rating_comment TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );

CREATE TABLE IF NOT EXISTS comments (
    id SERIAL PRIMARY KEY,
    ticket_id VARCHAR(64) NOT NULL,
    user_id INT NULL,
    user_name VARCHAR(150) NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
    );

CREATE TABLE IF NOT EXISTS ticket_history (
    id SERIAL PRIMARY KEY,
    ticket_id VARCHAR(64) NOT NULL,
    actor_name VARCHAR(150) NOT NULL,
    field VARCHAR(30) NOT NULL,
    old_value VARCHAR(150) NULL,
    new_value VARCHAR(150) NULL,
    created_at TIMESTAMP NOT NULL,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
    );

CREATE TABLE IF NOT EXISTS chat_messages (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL,
    sender_name VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    from_support SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );

CREATE TABLE IF NOT EXISTS notifications (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    body VARCHAR(500) NOT NULL,
    link VARCHAR(255) NULL,
    is_read SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );

CREATE TABLE IF NOT EXISTS passkeys (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL,
    credential_id VARCHAR(255) NOT NULL UNIQUE,
    public_key TEXT NOT NULL,
    sign_count BIGINT NOT NULL DEFAULT 0,
    label VARCHAR(100) NOT NULL DEFAULT 'This device',
    created_at TIMESTAMP NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );

CREATE INDEX idx_tickets_user ON tickets(user_id);

CREATE INDEX idx_tickets_status ON tickets(status);

CREATE INDEX idx_comments_ticket ON comments(ticket_id);

CREATE INDEX idx_history_ticket ON ticket_history(ticket_id);

CREATE INDEX idx_chat_user ON chat_messages(user_id);

CREATE INDEX idx_notif_user ON notifications(user_id, is_read);

