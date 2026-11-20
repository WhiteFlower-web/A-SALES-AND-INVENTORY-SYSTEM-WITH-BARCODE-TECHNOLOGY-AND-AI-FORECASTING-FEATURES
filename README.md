TITLE: A SALES AND INVENTORY SYSTEM WITH BARCODE TECHNOLOGY AND AI FORECASTING FEATURES

Group Members & Roles

ESTRECHO, KHERLEEN MAE S. - Project Manager / Lead Developer
ANIB, JOHN ALBERT - Documents
GARDE, RALPH ADRIAN - Documents

Tech Stack

Frontend: HTML5, CSS3, JavaScript (or React / Vue.js / Blade templates) with barcode scanner input / camera or USB HID barcode support
Backend: PHP (8.1+ / Laravel or native PHP) or Python (Flask / FastAPI) for POS, inventory, and AI demand forecasting endpoints
Database: MySQL (InnoDB, utf8mb4_unicode_ci — jj_stores)
AI Forecasting: Python (pandas, scikit-learn, statsmodels / Prophet or moving-average / regression models) or analytics service querying sales, sale_items, and stock_history
Tools & Version Control: Git, VS Code, phpMyAdmin / MySQL Workbench, XAMPP / Laragon
System Requirements & Prerequisites


Before you begin, ensure you have the following installed on your local machine:

PHP (v8.1+) with PDO / MySQLi extensions (or Node.js v18+ / Python v3.10+ if running separate API/forecasting services)
MySQL Server (v8.0+) / MariaDB via XAMPP or Laragon
Composer (if using PHP/Laravel) and/or pip / Python (if running the AI forecasting module)
Barcode Scanner (USB HID scanner or webcam/mobile scanner) and optional 58mm / 80mm thermal printer
Installation & Setup Instructions
Follow these step-by-step commands to set up the project locally:

1. Clone the Repository
git clone https://github.com/your-username/A-SALES-AND-INVENTORY-SYSTEM-WITH-BARCODE-TECHNOLOGY-AND-AI-FORECASTING-FEATURES.git
cd A-SALES-AND-INVENTORY-SYSTEM-WITH-BARCODE-TECHNOLOGY-AND-AI-FORECASTING-FEATURES

2. Install Dependencies
# For PHP / Laravel projects:
composer install
cp .env.example .env
php artisan key:generate

# For Node.js frontend/backend assets (if applicable):
npm install

# For Python AI forecasting service (if applicable):
pip install -r requirements.txt
Database Setup & Configuration
Follow these steps to configure your local database using the jj_stores schema:

Start your local database server (e.g., open XAMPP and start Apache and MySQL).

Create or import the database: Open phpMyAdmin (http://localhost/phpmyadmin) or run the SQL script directly. 
Your SQL script automatically creates the jj_stores database (CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci) along with the 10 core tables (stores, users, products, sales, sale_items, stock_history, pending_requests, notifications, audit_logs, and settings) and seed data.

Import the Database Schema: 
Locate your SQL schema file inside the repository folder (e.g., /database/jj_stores.sql or /sql/schema.sql) and import or execute it in phpMyAdmin or via CLI:
mysql -u root -p < database/jj_stores.sql

Configure Environment Variables: 
Open the .env file (or your database config file) in the root directory and update your database connection strings to match jj_stores:

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=jj_stores
DB_USERNAME=root
DB_PASSWORD=

Running the Application

# Start the local development server:
# For PHP / Laravel:
php artisan serve

# For Node.js (if applicable):
npm run dev


Open your browser and navigate to: http://localhost:8000 (or your XAMPP/Apache local URL such as http://localhost/jj_stores).

Default Login Credentials

Account	                Username	      Email Address	          Password
Admin Account	         Owner	          User@gmail.com		      admin123
Inventory Staff 	     inventory	      User@gmail.com	        admin123
Sales Staff	           cashier  	      User@gmail.com	        admin123
