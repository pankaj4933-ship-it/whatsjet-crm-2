# WhatsApp Marketing SaaS – Multi-Tenant Platform

A powerful, multi-tenant WhatsApp Cloud API SaaS application built on **Laravel 12** and **PHP 8.2+**. This platform enables businesses to connect their WhatsApp Business accounts, run bulk campaigns, automate replies, manage multi-agent inboxes, and process recurring subscription payments.

---

## 🚀 Key Features

- **WhatsApp Cloud API Integration:** Official Meta Graph API integration for high-deliverability messaging.
- **Multi-Tenant Architecture:** Separate workspaces, custom branding, and role-based permissions.
- **Broadcast & Campaigns:** Targeted bulk message campaigns with scheduling and delivery reports.
- **Interactive Bots & Automations:** Keyword-triggered auto-responders and interactive quick-reply flows.
- **Multi-Agent Shared Inbox:** Real-time customer conversation management for support and sales teams.
- **Payment Gateway Integrations:** Integrated billing with Stripe (Cashier), Razorpay, YooKassa, and more.
- **AI-Powered Replies:** Native OpenAI integration for intelligent automated responses.
- **Contact & Segment Management:** CSV import/export, tagging, and audience segmentation.

---

## 🛠️ Tech Stack & Requirements

- **PHP:** `^8.2` (with extensions: `BCMath`, `Ctype`, `cURL`, `DOM`, `Fileinfo`, `JSON`, `Mbstring`, `OpenSSL`, `PDO`, `Tokenizer`, `XML`)
- **Framework:** Laravel 12
- **Database:** MySQL 8.0+ or MariaDB 10.4+
- **Dependency Manager:** Composer 2.x
- **Frontend / Styling:** Blade, Tailwind CSS, JavaScript
- **Websockets:** Pusher / Laravel Echo

---

## 📥 Installation & Setup

### 1. Clone the Repository
```bash
git clone https://github.com/<your-username>/<your-repo-name>.git
cd <your-repo-name>
```

### 2. Install PHP Dependencies
```bash
composer install --optimize-autoloader --no-dev
```
*(Use `composer install` for local development).*

### 3. Environment Configuration
Copy the sample environment file and configure your environment:
```bash
cp .env.example .env
php artisan key:generate
```

Open `.env` and configure your database credentials:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=whatsjet
DB_USERNAME=root
DB_PASSWORD=your_password
```

### 4. Database Setup
Create an empty MySQL database named `whatsjet` (or your chosen DB name), then import the database schema:
```bash
mysql -u root -p whatsjet < database/database.sql
```
*(Or import `database/database.sql` via phpMyAdmin / TablePlus / DBeaver).*

### 5. Storage & Symlinks
```bash
php artisan storage:link
```
Ensure proper permissions for storage and cache directories:
```bash
chmod -R 775 storage bootstrap/cache
```

### 6. Run the Application
For local testing:
```bash
php artisan serve
```
Visit `http://localhost:8000` in your web browser.

---

## 🔑 Default Super Admin Credentials

Upon importing the initial database, log in with:
- **Email:** `superadmin@yourdomain.com`
- **Password:** `firstadmin123`

> ⚠️ **Important:** Change the default admin email and password immediately after initial login from the Admin settings!

---

## 🔒 Security & Best Practices

- Never commit your `.env` file containing database passwords, API secrets, or app keys.
- Keep your Meta / WhatsApp Cloud API System User Access Tokens secure.
- Configure SSL/HTTPS in production environments for WhatsApp Webhooks to function correctly.

---

## 📄 License
Proprietary software / End-user commercial license.
