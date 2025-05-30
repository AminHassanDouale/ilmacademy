# Production Deployment Checklist for smartdalib.work

## Pre-Deployment Steps

### 1. Environment Configuration

-   [ ] Update `.env` file with production values
-   [ ] Set `APP_ENV=production`
-   [ ] Set `APP_DEBUG=false`
-   [ ] Update `APP_URL=https://smartdalib.work`
-   [ ] Configure production database credentials
-   [ ] Set up Redis for caching and sessions
-   [ ] Configure mail settings for production

### 2. Security Configuration

-   [ ] Generate strong `APP_KEY` if not already done
-   [ ] Set `SESSION_SECURE_COOKIE=true`
-   [ ] Configure `SESSION_DOMAIN=.smartdalib.work`
-   [ ] Set up SSL certificate for HTTPS
-   [ ] Configure trusted proxies and hosts
-   [ ] Review and update CORS settings

### 3. Performance Optimization

-   [ ] Set `CACHE_DRIVER=redis`
-   [ ] Set `SESSION_DRIVER=redis`
-   [ ] Set `QUEUE_CONNECTION=redis`
-   [ ] Configure OpCache for PHP
-   [ ] Set up proper file permissions (755/775)

## Domain & DNS Configuration

### 4. DNS Settings

-   [ ] Point A record for `smartdalib.work` to server IP
-   [ ] Point A record for `www.smartdalib.work` to server IP
-   [ ] Configure CNAME if using CDN
-   [ ] Set up proper TTL values

### 5. SSL Certificate

-   [ ] Install SSL certificate for `smartdalib.work`
-   [ ] Install SSL certificate for `www.smartdalib.work`
-   [ ] Test SSL configuration
-   [ ] Verify SSL rating (A+ grade recommended)

## Server Configuration

### 6. Web Server (Nginx/Apache)

-   [ ] Configure virtual host for `smartdalib.work`
-   [ ] Set document root to `/path/to/project/public`
-   [ ] Configure PHP-FPM settings
-   [ ] Set up proper redirects (HTTP to HTTPS, www handling)
-   [ ] Configure security headers
-   [ ] Set up Gzip compression

### 7. Database Setup

-   [ ] Create production database: `smartdalib_ilmacademie`
-   [ ] Create database user with proper permissions
-   [ ] Import/migrate database schema
-   [ ] Seed initial data if required
-   [ ] Set up database backups

### 8. File Permissions & Storage

-   [ ] Set proper ownership: `chown -R www-data:www-data /path/to/project`
-   [ ] Set directory permissions: `chmod -R 755 storage bootstrap/cache`
-   [ ] Create storage symbolic link: `php artisan storage:link`
-   [ ] Configure file upload limits

## Application Deployment

### 9. Code Deployment

-   [ ] Clone/pull latest code from repository
-   [ ] Install Composer dependencies: `composer install --optimize-autoloader --no-dev`
-   [ ] Install NPM dependencies: `npm ci --only=production`
-   [ ] Build production assets: `npm run build`

### 10. Laravel Configuration

-   [ ] Run database migrations: `php artisan migrate --force`
-   [ ] Clear all caches: `php artisan config:clear cache:clear route:clear view:clear`
-   [ ] Cache configuration: `php artisan config:cache route:cache view:cache`
-   [ ] Generate storage link: `php artisan storage:link`

### 11. Queue & Scheduling

-   [ ] Set up queue workers with Supervisor
-   [ ] Configure cron job for Laravel scheduler
-   [ ] Test queue processing
-   [ ] Monitor queue workers

## Testing & Verification

### 12. Functionality Testing

-   [ ] Test homepage loads correctly
-   [ ] Test user registration and login
-   [ ] Test role-based menu system
-   [ ] Test file uploads and downloads
-   [ ] Test email functionality
-   [ ] Test database connections

### 13. Performance Testing

-   [ ] Run speed tests (GTmetrix, PageSpeed Insights)
-   [ ] Test under load
-   [ ] Monitor memory usage
-   [ ] Check for N+1 queries
-   [ ] Verify caching is working

### 14. Security Testing

-   [ ] Run security scan
-   [ ] Test HTTPS redirection
-   [ ] Verify security headers
-   [ ] Test for common vulnerabilities
-   [ ] Check file permissions

## Monitoring & Maintenance

### 15. Logging & Monitoring

-   [ ] Set up log rotation
-   [ ] Configure error monitoring (Sentry, Bugsnag)
-   [ ] Set up uptime monitoring
-   [ ] Configure performance monitoring
-   [ ] Set up alerts for critical issues

### 16. Backup Strategy

-   [ ] Set up automated database backups
-   [ ] Configure file system backups
-   [ ] Test backup restoration
-   [ ] Document backup procedures

### 17. Documentation

-   [ ] Document deployment process
-   [ ] Create maintenance procedures
-   [ ] Document troubleshooting steps
-   [ ] Update team on production URLs and access

## Post-Deployment

### 18. Final Verification

-   [ ] Verify all routes work correctly
-   [ ] Test user flows end-to-end
-   [ ] Check all integrations
-   [ ] Monitor error logs for 24-48 hours
-   [ ] Performance baseline measurement

### 19. Team Communication

-   [ ] Announce production deployment
-   [ ] Share production URLs
-   [ ] Provide access credentials where needed
-   [ ] Schedule post-deployment review

## Quick Commands for Production

```bash
# Deploy to production
./deploy-production.sh

# Check application status
php artisan about

# Monitor logs
tail -f storage/logs/laravel.log

# Clear caches
php artisan optimize:clear

# Cache everything
php artisan optimize

# Check queue status
php artisan queue:work --daemon

# Monitor performance
php artisan horizon:status
```

## Emergency Rollback Plan

-   [ ] Keep previous version backup
-   [ ] Document rollback procedure
-   [ ] Test rollback process
-   [ ] Have emergency contact list ready

---

**Production URL:** https://smartdalib.work  
**Admin Panel:** https://smartdalib.work/admin  
**API Endpoint:** https://smartdalib.work/api

**Deployment Date:** ****\_\_\_****  
**Deployed By:** ****\_\_\_****  
**Version/Commit:** ****\_\_\_****
