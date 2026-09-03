module.exports = {
  apps: [{
    name: 'corejs-backend',
    cwd: '/var/www/corejs/apps/backend',
    script: 'dist/main.js',
    env: {
      NODE_ENV: 'production',
      PORT: 3000,
      HOST: '127.0.0.1',
      CORS_ORIGIN: 'https://tudominio.com,https://www.tudominio.com',
      MASTER_DB_KEY: 'CAMBIA_POR_TU_KEY_64CHARS_HEX_AQUI',
      JWT_SECRET: 'CAMBIA_POR_TU_JWT_SECRET_AQUI',
      DB_HOST: '127.0.0.1',
      DB_PORT: 3306,
      DB_USER: 'corejs_user',
      DB_PASSWORD: 'CAMBIA_POR_TU_PASSWORD_MYSQL',
      DB_MASTER_NAME: 'core_master',
      SMTP_HOST: 'smtp.hostinger.com',
      SMTP_PORT: 465,
      SMTP_SECURE: 'true',
      SMTP_USER: 'no-reply@tudominio.com',
      SMTP_PASS: 'CAMBIA_PASSWORD_EMAIL',
      SMTP_FROM: '"CoreJS" <no-reply@tudominio.com>',
      PUPPETEER_HEADLESS: 'new',
      PUPPETEER_EXECUTABLE_PATH: '/usr/bin/google-chrome-stable'
    },
    instances: 1,
    exec_mode: 'fork',
    max_memory_restart: '1G',
    autorestart: true,
    watch: false,
    error_file: '/var/log/corejs/backend-error.log',
    out_file: '/var/log/corejs/backend-out.log',
    log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
    merge_logs: true
  }]
};
