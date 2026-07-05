# Welcome to GastroFlow 👋
> A complete web application for restaurants to register and take orders for customers, built with PHP, Alphine, and Docker...

![Tela do Caixa](public/assets/img/tela1.png)
![Tela da Cozinha](public/assets/img/tela2.png)
## Author
👤 **Henry Sampaio**

* Website: https://focuseven.netlify.app
* Github: [@FocusEvenGitHub](https://github.com/FocusEvenGitHub)
* LinkedIn: [@Henry Sampaio](https://linkedin.com/in/Henry-Sampaio)

## Show your support

Give a ⭐️ if this project helped you!

***

## 🧠 About

**GastroFlow** is a restaurant management platform that allows:

- 📋 Each restaurant to register its account
- 🍽️ To receive online customer orders
- 🛠️ To run in a Docker environment
- 🚀 To be easily customized and expanded

This README provides complete instructions for starting, developing, and contributing to the project.

---

## 🚀 Features

- 🧾 **Cashier** – interface for placing orders, selecting items, adding notes, and sending them to the kitchen.
- 👨‍🍳 **Kitchen** – real-time view of pending orders, with the option to finalize them.
- 🛠️ **Admin** – complete menu management: add, edit, and activate/deactivate items.
- 🔌 **RESTful API** – endpoints ready for integration with other systems.
- 🐳 **Docker Environment** – fast and standardized execution.

---

## 📦 Technologies Used

- 🐘 PHP 8.2 + Slim 4 (micro-framework)
- 🎲 Eloquent ORM (Illuminate Database)
- 🎨 Bootstrap 5 + Font Awesome
- ⚡ Alpine.js
- 🐬 MySQL
- 🔐 JWT (JSON Web Tokens)
- 🐳 Docker & Docker Compose

---

## 🧱 Project Structure
```
📦 GastroFlow
├── public/               # DocumentRoot (Slim Entry)
│   ├── index.php
│   ├── .htaccess
│   └── assets/
├── src/                  # Application code (PSR‑4)
│   ├── Controllers/
│   ├── Middleware/
│   ├── Models/
│   ├── Repositories/
│   ├── Services/
│   └── Validators/
├── legacy/               # Old Code (backup)
│   └── api, cashier, kitchen, admin
├── common/               # SQL schema
├── composer.json
├── Dockerfile
├── docker-compose.yml
├── .env.example
└── README.md
```

---

## 🛠️ Prerequisites

Before starting, make sure you have installed:

- 🐳 **Docker**
- 📦 **Docker Compose**
- 🧠 Code editor (VS Code, PHPStorm, etc.)

---

## 🔧 Installation

1. Clone the repository:

```bash
git clone https://github.com/FocusEvenGitHub/GastroFlow.git
```
2. Access the folder:

```
cd GastroFlow
```
3. Copy .env.example to .env and fill in the environment variables:

```
cp .env.example .env
openssl rand -base64 48    # gerar JWT_SECRET
```
- Paste the string generated in `.env` as `JWT_SECRET`
4. Start the containers:
```
docker compose up -d
``` 
5. Access in your browser:
```
http://localhost:8080
```
---

## 📦 Gerenciamento de Dependências (Composer)

Após subir os containers com `docker compose up -d`, você pode executar comandos do Composer diretamente de dentro do container:

```bash
# Instalar as dependências atuais (baseado no composer.lock)
docker compose exec web composer install

# Atualizar as dependências (modifica o composer.lock)
docker compose exec web composer update

# Adicionar uma nova dependência
docker compose exec web composer require nome-do-pacote

# Remover uma dependência
docker compose exec web composer remove nome-do-pacote
```

Alternativa usando `docker exec` com o nome do container:

```bash
docker exec -it restaurant_web composer update
```

> ⚠️ Lembre-se de que o `composer.json` e `composer.lock` do host estão montados como volumes no container, então as alterações feitas dentro do container são refletidas automaticamente no seu projeto local.

---

## 🧪 How to Use

After `docker compose up -d`:

- Access **Cashier** – create orders by table number, items and notes.
- Access **Kitchen** – see pending orders appear in real time, mark them as completed.
- Access **Admin** – login with the default user (`admin` / `admin123`), then manage the menu (add / enable / disable items).
- All changes are persisted inside the MySQL container.
- To use the API directly, refer to the cURL examples below.
---
## 📍 Endpoints
Important endpoints here when the backend is documented
``` 
| Method | Endpoint                     | Description                      | Auth   |
|--------|------------------------------|----------------------------------|--------|
| GET    | `/api/menu`                  | Full menu                        | Public |
| POST   | `/api/orders`                | Create a new order               | Public |
| GET    | `/api/orders?status=pending` | List orders by status            | Public |
| POST   | `/api/orders/{id}/complete`  | Mark an order as done            | Public |
| POST   | `/api/login`                 | Obtain a JWT token               | Public |
| GET    | `/api/admin/menu`            | Menu (admin)                     | JWT    |
| POST   | `/api/admin/items`           | Add a menu item                  | JWT    |
| PATCH  | `/api/admin/items/{id}`      | Toggle item availability         | JWT    |
```

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

# 4. Complete an order (replace {id} with real order id)
curl -s -X POST http://localhost:8080/api/orders/1/complete | python -m json.tool

# 5. Login as admin (default credentials: admin/admin123)
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
  -d '{"name":"Caesar Salad","price":14.90,"category_name":"Pratos Principais","description":"Fresh salad"}' | python -m json.tool

# 6c. Toggle item availability (1 = available, 0 = unavailable)
curl -s -X PATCH http://localhost:8080/api/admin/items/1 \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"available":false}' | python -m json.tool
  ```

---

## 📐 Commit Convention

This project follows [Conventional Commits](https://www.conventionalcommits.org/) with emojis.  
For a complete guide, check [COMMIT_CONVENTION.md](COMMIT_CONVENTION.md).

## 🧩 Contributing

If you want to contribute:

1. Fork the repository ✌️
2. Create a branch:
``` 
git checkout -b feature/feature-name
```
3. Commit your changes
``` 
git commit -m "feat: description"
``` 
4. Push to the repository:
``` 
git push origin feature/feature-name
```

5. Open a Pull Request 📨
---
### Issue

If you want to discuss improvements or have technical questions, open an issue on GitHub or contact the maintainers.

Thank you for contributing to and using GastroFlow! ✨