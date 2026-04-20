# Semester PHP Project

Welcome to our semester project for the PHP course! This repository is maintained by our team of four. This document serves as our central hub, outlining the project roadmap, our development environment, folder structure, and the Git workflow we will follow.

## 🛠️ Tech Stack & Tools

To ensure a smooth and professional development process, we have agreed to use the following tools:

### 1. Local Development Environment (WAMP/XAMPP)
We are using local servers to run our PHP backend. 
- **Setup:** You can use either WAMP or XAMPP. (WAMP is highly recommended as it is easy to activate via its `.exe` file).
- **Workspace:** Place the project folder inside the `C:/wamp64/www/` directory.
- **Access:** The project can be viewed in the browser at `http://localhost/project/`.

### 2. Database (PostgreSQL)
Instead of standard options, we have chosen **PostgreSQL** for our database. It is a highly powerful, enterprise-grade relational database system. Using it for this project will challenge us, improve our backend skills, and be a great asset for our future professional careers.

### 3. Terminal (Warp)
For our command-line operations, we highly recommend using **Warp**. 
- It is a modern, blazing-fast terminal built in Rust. 
- It acts as an intelligent wrapper for your main terminal, offering AI-driven command suggestions and translations.
- **Note:** We recommend configuring Warp to use `bash.exe` (Git Bash) rather than PowerShell, as it provides a Linux-like environment which is much cooler and standard for development.


## 📂 Project Structure

We have organized our codebase to strictly separate the logic from the presentation.

```text
PROJECT/
├── includes/           # Core logic and backend (PHP)
│   ├── db.php          # PostgreSQL database connection
│   └── functions.php   # Main functions (Login, Bookings, Analytics)
├── assets/             # Static files (CSS, JS, and Images)
├── index.php           # Landing page / Login
├── admin.php           # Admin panel and the same time and the dashboard inside (Statistics and Financials)
├── .env                # Database credentials (kept separate for security)
└── README.md           # Project documentation
```

---

## 🌿 Git Workflow & Collaboration

To avoid merge conflicts and ensure everyone can work independently, we are strictly following a feature-branch workflow. 

### Tools
We recommend installing the **Git Graph** extension in VS Code. It provides beautiful, real-time visual animations of our repository's history, making it incredibly easy to see exactly where we are in the codebase and how our branches interact.

### Branching Strategy
Everyone will work on their own dedicated branch originating from `main` (or `master`). Once a feature is complete and tested, it will be merged back.

### Branching Strategy (Integration Workflow)
To keep our production code safe and avoid messy cross-merging, we are using an **Integration Branch (`develop`)** strategy. 

**Rule of thumb:** NEVER merge directly into `main`. NEVER merge directly into each other's branches. All collaboration happens through the `develop` branch.

```text
  (main)   (develop)   (renis)    (lusi)     (geri)     (luisi)
    │          │          │          │          │          │
    │<─────────●          │          │          │          │  5. Final tests passed! develop merges into main
    │          │          │          │          │          │
    │          │<───────────────────────────────●          │  4. Geri merges his code to develop
    │          │          │          │          │          │
    │          │─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─>●          │  3. Geri pulls from develop to get Renis & Lusi's code
    │          │          │          │          │          │
    │          │<────────────────────●          │          │  2. Lusi merges her code to develop
    │          │          │          │          │          │
    │          │<─────────●          │          │          │  1. Renis merges his code to develop
    │          │          │          │          │          │
    ●──────────┴──────────┴──────────┴──────────┴──────────┘  0. Branches created from 'develop' (NOT main)
    │
  main (start)
```

**Workflow Steps:**
1. **Never touch `main`:** The `main` branch is only for the finished, 100% working project.
2. **Start from `develop`:** Make sure you are on the `develop` branch before creating your personal branch: `git checkout -b your_name develop`
3. **Work and Push:** Write your code, commit, and push to your personal branch.
4. **Merge to `develop`:** Open a Pull Request to merge your work into the `develop` branch. 
5. **Sync up:** Need someone else's code? Once they merge it to `develop`, simply run `git pull origin develop` while on your branch to get their updates.

*We are looking forward to building a beautiful project together and learning a lot along the way!*
