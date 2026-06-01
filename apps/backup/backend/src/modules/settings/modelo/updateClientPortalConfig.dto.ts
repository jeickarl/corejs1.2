import { ApiPropertyOptional } from '@nestjs/swagger';
import { IsBoolean, IsOptional, IsString } from 'class-validator';

export class UpdateClientPortalConfigDto {
  @ApiPropertyOptional({ example: true })
  @IsOptional()
  @IsBoolean()
  enableLookupById?: boolean;

  @ApiPropertyOptional({ example: true })
  @IsOptional()
  @IsBoolean()
  showTimeline?: boolean;

  @ApiPropertyOptional({ example: true })
  @IsOptional()
  @IsBoolean()
  allowApproval?: boolean;

  @ApiPropertyOptional({ example: 'Bienvenido' })
  @IsOptional()
  @IsString()
  homeTitle?: string;

  @ApiPropertyOptional({ example: 'Servicio técnico' })
  @IsOptional()
  @IsString()
  homeSubtitle?: string;

  @ApiPropertyOptional({ example: 'https://wa.me/573000000000' })
  @IsOptional()
  @IsString()
  whatsappLink?: string;

  @ApiPropertyOptional({ example: 'Calle 123 #45-67' })
  @IsOptional()
  @IsString()
  addressText?: string;

  @ApiPropertyOptional({ example: 'Lun-Vie 9-6' })
  @IsOptional()
  @IsString()
  hoursText?: string;

  @ApiPropertyOptional({ example: '<iframe ...></iframe>' })
  @IsOptional()
  @IsString()
  mapEmbedUrl?: string;
}

