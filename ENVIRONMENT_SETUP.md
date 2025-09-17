# Environment Configuration Guide

## 🔧 Step 1.2: Complete Environment Setup

You need to manually update your `.env` file with the following configurations:

### 1. Open your `.env` file and update these existing values:

```env
APP_NAME="AI Music Generator Backend"
APP_URL=http://localhost:8000
QUEUE_CONNECTION=redis
CACHE_STORE=redis
```

### 2. Add these new configuration sections to your `.env` file:

```env
# ================================================
# TOPMEDIAI API CONFIGURATION (REQUIRED)
# ================================================
TOPMEDIAI_API_KEY=your_topmediai_api_key_here
TOPMEDIAI_BASE_URL=https://api.topmediai.com
TOPMEDIAI_TIMEOUT=30
TOPMEDIAI_RETRY_ATTEMPTS=3
TOPMEDIAI_RETRY_DELAY=1000

# ================================================
# FILE STORAGE CONFIGURATION
# ================================================
GENERATED_CONTENT_PATH=storage/app/generated
THUMBNAIL_PATH=storage/app/thumbnails
MAX_FILE_SIZE=52428800
ALLOWED_AUDIO_FORMATS=mp3,wav,mp4
CLEANUP_DAYS=30

# ================================================
# RATE LIMITING CONFIGURATION
# ================================================
RATE_LIMIT_FREE_TIER=5
RATE_LIMIT_PREMIUM_TIER=50
RATE_LIMIT_WINDOW=3600

# ================================================
# SUBSCRIPTION & BILLING
# ================================================
FREE_TIER_LIMIT=10
PREMIUM_FEATURES_ENABLED=true

# ================================================
# SECURITY SETTINGS
# ================================================
CORS_ALLOWED_ORIGINS=*
CORS_ALLOWED_METHODS=GET,POST,PUT,DELETE,OPTIONS
CORS_ALLOWED_HEADERS=Content-Type,Authorization,X-Device-ID
API_RATE_LIMIT=100
API_RATE_LIMIT_WINDOW=60

# ================================================
# DEVICE TRACKING
# ================================================
DEVICE_ID_SALT=your_random_salt_here_for_device_fingerprinting
DEVICE_LINKING_ENABLED=true
MAX_DEVICES_PER_USER=3

# ================================================
# JOB PROCESSING
# ================================================
QUEUE_TIMEOUT=300
QUEUE_MEMORY_LIMIT=512
QUEUE_SLEEP=3
QUEUE_TRIES=3
```

### 3. Important Notes:

#### 🔑 **Required API Key**
- You MUST replace `your_topmediai_api_key_here` with your actual TopMediai API key
- Without this, the music generation will not work

#### 🔐 **Security Salt**
- Replace `your_random_salt_here_for_device_fingerprinting` with a random string
- This is used for secure device ID generation
- Example: `my_super_secret_salt_12345_random`

#### 📁 **Storage Directories**
- ✅ Already created: `storage/app/generated` (for audio files)
- ✅ Already created: `storage/app/thumbnails` (for album artwork)

#### 🚀 **Redis Requirement**
- You'll need Redis running for queue processing
- Install Redis on your system
- Default connection settings should work: `127.0.0.1:6379`

### 4. Verification Checklist:

After updating your `.env` file, verify:

- [ ] `.env` contains all new configuration variables
- [ ] `TOPMEDIAI_API_KEY` is set to your real API key
- [ ] `DEVICE_ID_SALT` is set to a random string
- [ ] `QUEUE_CONNECTION=redis`
- [ ] `CACHE_STORE=redis`
- [ ] Storage directories exist (already created)

### 5. Next Steps:

Once your `.env` file is updated:
1. Test the configuration: `php artisan config:cache`
2. Verify database connection: `php artisan migrate:status`
3. Ready for Step 1.3: Installing additional packages

---

**Status**: Step 1.2 will be complete after you manually update the `.env` file with the above configurations.