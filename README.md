<p align="center">
  <img src="public/assets/img/logo.png" alt="GastroFlow Logo" width="200"/>
</p>

<h1 align="center">🍽️ GastroFlow</h1>

<p align="center">
  <b>A complete restaurant order management system</b><br>
  Built with PHP, Slim 4, Alpine.js, and Docker
</p>

<p align="center">
  <a href="https://github.com/FocusEvenGitHub/GastroFlow">
    <img src="https://img.shields.io/badge/PHP-^8.1-777BB4?style=flat-square&logo=php" alt="PHP 8.1+"/>
  </a>
  <a href="https://github.com/FocusEvenGitHub/GastroFlow">
    <img src="https://img.shields.io/badge/Slim-4-8A2BE2?style=flat-square" alt="Slim 4"/>
  </a>
  <a href="https://www.docker.com/">
    <img src="https://img.shields.io/badge/Docker-Ready-2496ED?style=flat-square&logo=docker" alt="Docker Ready"/>
  </a>
  <a href="https://github.com/FocusEvenGitHub/GastroFlow">
    <img src="https://img.shields.io/badge/PRs-Welcome-brightgreen?style=flat-square" alt="PRs Welcome"/>
  </a>
  <a href="https://github.com/FocusEvenGitHub/GastroFlow/stargazers">
    <img src="https://img.shields.io/github/stars/FocusEvenGitHub/GastroFlow?style=flat-square" alt="GitHub Stars"/>
  </a>
</p>

---

## 📸 Screenshots

<div align="center">
  <table>
    <tr>
      <td align="center" width="50%">
        <img src="public/assets/img/tela_cashier.png" alt="Cashier Interface" width="100%"/>
        <br><sub>🧾 <b>Cashier</b> — Place orders, select items, add notes & send to kitchen</sub>
      </td>
      <td align="center" width="50%">
        <img src="public/assets/img/tela_kitchen.png" alt="Kitchen Interface" width="100%"/>
        <br><sub>👨‍🍳 <b>Kitchen</b> — Real-time view of pending orders & mark as done</sub>
      </td>
    </tr>
    <tr>
      <td align="center" width="50%">
        <img src="public/assets/img/tela_admin.png" alt="Admin Interface" width="100%"/>
        <br><sub>🛠️ <b>Admin</b> — Complete menu management (add, edit, enable/disable items)</sub>
      </td>
      <td align="center" width="50%">
        <img src="public/assets/img/tela_relatorio.png" alt="Reports Dashboard" width="100%"/>
        <br><sub>📊 <b>Reports</b> — Dashboard with sales data and analytics</sub>
      </td>
    </tr>
  </table>
</div>

---

## 🧠 About

**GastroFlow** is a web application designed for restaurants to register accounts, receive online customer orders, and manage their entire operation — from the cashier to the kitchen. It runs in a Docker environment for fast, standardized setup and is easy to customize and expand.

---

## 🚀 Features

- **🧾 Cashier** — Intuitive interface for placing orders by table number, selecting menu items, adding special notes, and sending them straight to the kitchen.
- **👨‍🍳 Kitchen** — Real-time display of pending orders with auto-refresh; mark orders as completed with one click.
- **🛠️ Admin** — Full menu CRUD: add new items, edit prices/descriptions, toggle availability, and manage categories.
- **📊 Reports** — Visual dashboard with sales summaries, order history, and performance metrics.
- **🔌 RESTful API** — Well-documented endpoints ready for integration with external systems, POS devices, or mobile apps.
- **🐳 Docker Environment** — Pre-configured Docker Compose setup with PHP, Nginx, and MySQL containers.
- **🧾 Thermal Printing** — Integrated with ESC/POS printers for automatic receipt printing at the cashier.

---

## 📦 Tech Stack

| Technology | Purpose |
|---|---|
| 🐘 **PHP 8.2+** | Backend language |
| ⚡ **Slim 4** | Micro-framework (routing, middleware) |
| 🎲 **Eloquent ORM** | Database interaction (Illuminate Database) |
| 🎨 **Bootstrap 5 + Font Awesome** | Frontend UI & icons |
| ⚡ **Alpine.js** | Lightweight JavaScript interactivity |
| 🐬 **MySQL** | Database |
| 🔐 **JWT** | JSON Web Token authentication |
| 🐳 **Docker & Docker Compose** | Containerized development & deployment |
| 🧾 **ESC/POS** | Thermal printer support (mike42/escpos-php) |

---

## 🧱 Project Structure

```
📦 GastroFlow
├── public/                  # DocumentRoot (Slim entry point)
│   ├── index.php            # Front controller
│   ├── .htaccess            # URL rewriting
│   ├── assets/              # Static assets (CSS, JS, images)
│   ├── admin/               # Admin panel views
│   ├── cashier/             # Cashier interface views
│   └── kitchen/             # Kitchen display views
├── src/                     # Application code (PSR-4)
│   ├── Controllers/         # Request handlers
│   ├── Middleware/           # Auth, CORS, etc.
│   ├── Models/              # Eloquent models
│   ├── Repositories/        # Data access layer
│   ├── Services/            # Business logic
│   └── Validators/          # Input validation
├── common/                  # SQL schema & migrations
├── bin/                     # CLI scripts
├── Dockerfile               # PHP container definition
├── docker-compose.yml       # Multi-container setup
├── .env.example             # Environment template
├── composer.json            # PHP dependencies
└── README.md
```

---

## 🛠️ Prerequisites

Before you begin, make sure you have installed:

- 🐳 **Docker** (v20.10+)
- 📦 **Docker Compose** (v2.0+)
- 🧠 A code editor (VS Code, PHPStorm, etc.)

---

## 🔧 Installation

### 1. Clone the repository

```bash
git clone https://github.com/FocusEvenGitHub/GastroFlow.git
```

### 2. Enter the project directory

```bash
cd GastroFlow
```

### 3. Configure environment variables

```bash
cp .env.example .env
```

Generate a secure JWT secret:

```bash
openssl rand -base64 48
```

Paste the generated string into `.env` as `JWT_SECRET`.

### 4. Start the containers

```bash
docker compose up -d
```

### 5. Open in your browser

**Application:** [http://localhost:8080](http://localhost:8080)

**Interactive API docs:** [http://localhost:8080/api/docs](http://localhost:8080/api/docs)

---

## 📦 Composer Dependency Management

Once the containers are running with `docker compose up -d`, you can run Composer commands from inside the container:

```bash
# Install current dependencies (based on composer.lock)
docker compose exec web composer install

# Update dependencies (modifies composer.lock)
docker compose exec web composer update

# Add a new dependency
docker compose exec web composer require package-name

# Remove a dependency
docker compose exec web composer remove package-name
```

Alternatively, using `docker exec` directly:

```bash
docker exec -it gastroflow_web composer update
```

> ⚠️ The `composer.json` and `composer.lock` files on your host are mounted as volumes in the container, so changes made inside the container are automatically reflected in your local project.

---

## 🧪 How to Use

After `docker compose up -d`:

- **Cashier** → Create orders by table number, select items, add special notes, and send them to the kitchen.
- **Kitchen** → See pending orders appear in real time; click to mark them as completed.
- **Admin** → Login with the default credentials (`admin` / `admin123`), then manage menu items (add, edit, enable/disable).
- **Reports** → View sales summaries and order statistics.
- All data is persisted in the MySQL container.

---

## 📖 Interactive API Documentation

Access the complete, interactive API documentation at:

👉 **[http://localhost:8080/api/docs](http://localhost:8080/api/docs)**

There you'll find:

- All endpoints organized by category
- Request and response schemas
- **"Try it out"** button to test calls directly from the browser
- Integrated JWT authentication (click **Authorize** and paste your token)

---

## 🧪 Testing with cURL

Make sure the containers are running (`docker compose up -d`).  
All endpoints return **JSON**.

```bash
# 1. Get the full menu
curl -s http://localhost:8080/api/menu | python -m json.tool

# 2. Create a new order
curl -s -X POST http://localhost:8080/api/orders \
  -H "Content-Type: application/json" \
  -d '{"table":"3","items":[{"id":1,"quantity":2,"notes":"no onion"}]}' | python -m json.tool

# 3. List pending orders
curl -s http://localhost:8080/api/orders?status=pending | python -m json.tool

# 4. Complete an order (replace {id} with the real order id)
curl -s -X POST http://localhost:8080/api/orders/1/complete | python -m json.tool

# 5. Login as admin (default: admin / admin123)
curl -s -X POST http://localhost:8080/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}' | python -m json.tool

# 6. Use the token to access admin routes
TOKEN="paste-your-token-here"

# 6a. Get menu (admin version)
curl -s -H "Authorization: Bearer $TOKEN" http://localhost:8080/api/admin/menu | python -m json.tool

# 6b. Add a new menu item
curl -s -X POST http://localhost:8080/api/admin/items \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"name":"Caesar Salad","price":14.90,"category_name":"Main Courses","description":"Fresh salad with croutons"}' | python -m json.tool

# 6c. Toggle item availability (1 = available, 0 = unavailable)
curl -s -X PATCH http://localhost:8080/api/admin/items/1 \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"available":false}' | python -m json.tool
```

---

## 📐 Commit Convention

This project follows [Conventional Commits](https://www.conventionalcommits.org/) with emojis.  
For a complete guide — including types, scopes, and real examples — check [COMMIT_CONVENTION.md](COMMIT_CONVENTION.md).

### 🏷️ Releases & Changelog

- The [CHANGELOG.md](CHANGELOG.md) is manually curated and updated before each release.
- A new annotated tag is created for every version (`vX.Y.Z`) and pushed to GitHub.
- See the **Release & Changelog Workflow** section in [COMMIT_CONVENTION.md](COMMIT_CONVENTION.md) for the full step-by-step process.

---

## Spec-driven development

New features, fixes, and improvements are planned before they're implemented:

- **`specs/`** holds one file per change (`specs/NNN-short-feature-name.md`), from proposal through implementation and validation. `specs/000-project-baseline.md` is a code-verified snapshot of the system as a starting point, and `specs/_template.md`/`specs/README.md` document the full workflow and lifecycle.
- **`CLAUDE.md`** at the repo root holds persistent, project-specific instructions (real architecture, available commands, conventions, security rules) for AI-assisted work in this repo.
- **`/spec-plan <description>`** turns a plain-language request into a new spec file under `specs/`, based on an investigation of the real code — it never touches application code or the database.
- **`/spec-implement specs/NNN-feature-name.md`** implements an existing spec step by step, keeping its checklist, implementation log, and validation evidence in sync with the actual work done.
- Specs are versioned in Git right alongside the code they describe, so history and review work the same way for "what we built" as for "why we built it."

---

## 🧩 Contributing

Contributions are always welcome! Here's how to get started:

1. **Fork** the repository ✌️
2. **Create a branch:**

   ```bash
   git checkout -b feature/feature-name
   ```
3. **Commit your changes:**

   ```bash
   git commit -m "feat: description"
   ```
4. **Push to the branch:**

   ```bash
   git push origin feature/feature-name
   ```
5. **Open a Pull Request** 📨

### Issues

If you'd like to discuss improvements, report a bug, or ask technical questions, please [open an issue](https://github.com/FocusEvenGitHub/GastroFlow/issues) on GitHub.

---

## 👤 Author

**Henry Sampaio**

- 🌐 Website: [https://focuseven.netlify.app](https://focuseven.netlify.app)
- 🐙 GitHub: [@FocusEvenGitHub](https://github.com/FocusEvenGitHub)
- 💼 LinkedIn: [Henry Sampaio](https://linkedin.com/in/Henry-Sampaio)

---

## ⭐ Show your support

If GastroFlow helped you or your business, please give it a ⭐️ on GitHub — it motivates further development!

---

<p align="center">
  Made with ❤️ by <a href="https://github.com/FocusEvenGitHub">Henry Sampaio</a>
</p>
