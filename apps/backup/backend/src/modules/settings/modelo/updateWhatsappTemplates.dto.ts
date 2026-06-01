import { ApiPropertyOptional } from '@nestjs/swagger';
import { IsOptional, IsString } from 'class-validator';

export class UpdateWhatsappTemplatesDto {
  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  reception?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  ready?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  delivery?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  sale?: string;
}

