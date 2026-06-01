import { ValidationPipe } from '@nestjs/common';
import { NestFactory } from '@nestjs/core';
import { DocumentBuilder, SwaggerModule } from '@nestjs/swagger';
import { config as loadEnv } from 'dotenv';
import { json, urlencoded } from 'express';
import fs from 'node:fs';
import path from 'node:path';
import { AppModule } from './app.module';

async function bootstrap() {
  const envCandidates = [
    path.resolve(process.cwd(), '.env'),
    path.resolve(process.cwd(), 'apps/backend/.env'),
    path.resolve(process.cwd(), 'legacy/config/.env.local'),
  ];
  const envPath = envCandidates.find((p) => fs.existsSync(p));
  if (envPath) {
    loadEnv({ path: envPath });
  } else {
    loadEnv();
  }
  const app = await NestFactory.create(AppModule);

  app.setGlobalPrefix('api');
  const rawCorsOrigin = (process.env.CORS_ORIGIN ?? '').trim();
  const corsOrigins = rawCorsOrigin
    .split(',')
    .map((v) => v.trim())
    .filter(Boolean);

  app.enableCors({
    origin: corsOrigins.length > 0 ? corsOrigins : true,
    credentials: true,
  });

  app.use(json({ limit: '2mb' }));
  app.use(urlencoded({ extended: true, limit: '2mb' }));

  app.useGlobalPipes(
    new ValidationPipe({
      whitelist: true,
      transform: true,
      forbidNonWhitelisted: true,
    }),
  );

  const swaggerConfig = new DocumentBuilder()
    .setTitle('CoreJS API')
    .setVersion('0.0.0')
    .build();
  const swaggerDocument = SwaggerModule.createDocument(app, swaggerConfig);
  SwaggerModule.setup('api/docs', app, swaggerDocument);

  await app.listen(Number(process.env.PORT ?? 3000));
}
bootstrap();
