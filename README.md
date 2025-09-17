# AI Music Generator Backend

A Laravel-based backend API for an AI Music Generator application that integrates with TopMediai API v3 to generate music, lyrics, and vocal tracks.

## 🎵 Features

- **Music Generation**: Create instrumental and vocal tracks using AI
- **Lyrics Generation**: Generate song lyrics based on prompts and themes
- **Singer Integration**: Add AI-generated vocals to music tracks
- **Format Conversion**: Convert between MP3, WAV, and MP4 formats
- **Device-based Authentication**: Track users without traditional login
- **Subscription Management**: Handle free tier limits and premium features
- **File Management**: Secure storage and streaming of generated content

## 🛠️ Tech Stack

- **Framework**: Laravel 12
- **Database**: SQLite (development) / MySQL (production)
- **Queue System**: Redis
- **External API**: TopMediai API v3
- **Authentication**: Device ID based (no user registration)
- **File Storage**: Local filesystem with secure serving

## 📋 Requirements

- PHP 8.2+
- Composer
- Redis Server
- SQLite/MySQL
- TopMediai API Key

## 🚀 Installation

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd ai-music-generator-backend
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Environment setup**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Configure environment variables**
   ```env
   # Database
   DB_CONNECTION=sqlite
   DB_DATABASE=database/database.sqlite

   # TopMediai API
   TOPMEDIAI_API_KEY=your_api_key_here
   TOPMEDIAI_BASE_URL=https://api.topmediai.com

   # Queue
   QUEUE_CONNECTION=redis
   REDIS_HOST=127.0.0.1
   REDIS_PASSWORD=null
   REDIS_PORT=6379
   ```

5. **Database setup**
   ```bash
   php artisan migrate
   php artisan db:seed
   ```

6. **Start services**
   ```bash
   # Start Laravel development server
   php artisan serve

   # Start queue worker (in separate terminal)
   php artisan queue:work

   # Start Redis server (if not running)
   redis-server
   ```

## 📚 API Documentation

### Core Endpoints

#### Music Generation
- `POST /api/generate-music` - Generate music tracks
- `POST /api/generate-lyrics` - Generate lyrics only
- `POST /api/generate-singer` - Add vocals to tracks

#### Task Management
- `GET /api/task-status/{task_id}` - Check generation progress
- `GET /api/user-content` - List user's generated content

#### File Management
- `GET /api/download/{file_id}` - Download generated files
- `POST /api/convert-format` - Convert file formats

#### Device & Subscription
- `POST /api/register-device` - Register device ID
- `POST /api/validate-purchase` - Validate subscriptions
- `GET /api/usage-stats` - Check usage limits

### Request Examples

#### Generate Music
```json
POST /api/generate-music
{
    "device_id": "unique_device_identifier",
    "prompt": "Upbeat pop song about summer",
    "mood": "happy",
    "genre": "pop",
    "instruments": ["guitar", "drums", "piano"],
    "language": "english",
    "duration": 120
}
```

#### Check Task Status
```json
GET /api/task-status/task_123456
Response:
{
    "task_id": "task_123456",
    "status": "completed",
    "progress": 100,
    "file_url": "/api/download/file_789",
    "metadata": {
        "duration": 120,
        "format": "mp3",
        "file_size": "4.2MB"
    }
}
```

## 🏗️ Project Structure

```
ai-music-generator-backend/
├── app/
│   ├── Http/Controllers/     # API Controllers
│   ├── Models/              # Eloquent Models
│   ├── Services/            # TopMediai API Services
│   ├── Jobs/                # Queue Jobs
│   └── Exceptions/          # Custom Exceptions
├── database/
│   ├── migrations/          # Database Migrations
│   └── seeders/            # Database Seeders
├── routes/
│   └── api.php             # API Routes
├── config/
│   └── topmediai.php       # TopMediai Configuration
└── storage/
    └── app/
        ├── generated/      # Generated Content Files
        └── thumbnails/     # Generated Thumbnails
```

## 🔧 Development

### Running Tests
```bash
php artisan test
```

### Code Style
```bash
composer run-script pint
```

### Queue Monitoring
```bash
php artisan queue:monitor
```

### Clearing Caches
```bash
php artisan optimize:clear
```

## 🚀 Deployment

### Production Setup
1. Configure production environment variables
2. Set up SSL certificates
3. Configure production database
4. Set up Redis for queue processing
5. Configure file storage and backups
6. Set up monitoring and logging

### Docker Deployment (Optional)
```bash
# Build and run with Docker
docker-compose up -d
```

## 📊 Monitoring

- **Queue Jobs**: Monitor background job processing
- **API Usage**: Track TopMediai API usage and limits
- **File Storage**: Monitor storage usage and cleanup
- **Error Tracking**: Log and track application errors

## 🔐 Security

- **API Key Protection**: Secure storage of TopMediai API keys
- **Rate Limiting**: Prevent API abuse
- **File Access Control**: Secure file serving with permissions
- **Input Validation**: Comprehensive request validation

## 📝 License

This project is proprietary software for the AI Music Generator application.

## 🤝 Contributing

Please read our development guidelines before contributing to this project.

## 📞 Support

For support and questions, please contact the development team.

---

**Version**: 1.0.0  
**Last Updated**: December 2024  
**Laravel Version**: 12.x