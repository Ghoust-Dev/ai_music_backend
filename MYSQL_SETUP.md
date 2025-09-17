# MySQL Database Configuration Guide

## 🗄️ MySQL Setup for AI Music Generator Backend

You need to update your `.env` file to use MySQL database instead of SQLite.

### 1. Update Database Configuration in .env

Replace the SQLite configuration with MySQL settings:

```env
# ================================================
# DATABASE CONFIGURATION - MYSQL
# ================================================
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=db_ai_music_generator
DB_USERNAME=root
DB_PASSWORD=your_mysql_password_here

# Remove or comment out SQLite config:
# DB_CONNECTION=sqlite
# DB_DATABASE=database/database.sqlite
```

### 2. Create MySQL Database

Before running migrations, create the database:

#### Option A: Using MySQL Command Line
```sql
mysql -u root -p
CREATE DATABASE db_ai_music_generator CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;
```

#### Option B: Using phpMyAdmin or MySQL Workbench
1. Open your MySQL management tool
2. Create new database: `db_ai_music_generator`
3. Set charset to `utf8mb4` and collation to `utf8mb4_unicode_ci`

### 3. Complete .env Configuration

Make sure your `.env` file has all these MySQL-related settings:

```env
# Application Settings
APP_NAME="AI Music Generator Backend"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database Configuration
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=db_ai_music_generator
DB_USERNAME=root
DB_PASSWORD=your_mysql_password_here

# Queue Configuration (keep Redis)
QUEUE_CONNECTION=redis
CACHE_STORE=redis

# Redis Configuration
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Add all the TopMediai and other configurations from ENVIRONMENT_SETUP.md
TOPMEDIAI_API_KEY=your_topmediai_api_key_here
TOPMEDIAI_BASE_URL=https://api.topmediai.com
# ... (rest of the configuration from ENVIRONMENT_SETUP.md)
```

### 4. Test Database Connection

After updating your `.env` file, test the connection:

```bash
php artisan config:clear
php artisan migrate:status
```

### 5. Important Notes

- **Replace `your_mysql_password_here`** with your actual MySQL root password
- **Create the database first** before running migrations
- **Ensure MySQL service is running** on your system
- **Default port 3306** should work unless you've changed it

### 6. Next Steps

Once your `.env` is updated with MySQL configuration:
1. Test database connection
2. Run initial migrations
3. Continue with Step 1.4 Laravel configuration

---

**Ready to proceed?** Update your `.env` file with the MySQL configuration above, then let me know and I'll continue with Step 1.4!