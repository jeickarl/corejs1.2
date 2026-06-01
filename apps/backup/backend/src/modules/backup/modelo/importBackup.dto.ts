import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsIn, IsObject, IsOptional } from 'class-validator';

export class ImportBackupDto {
  @ApiProperty({ enum: ['replace', 'append'] })
  @IsIn(['replace', 'append'])
  mode!: 'replace' | 'append';

  @ApiPropertyOptional({ type: Object })
  @IsOptional()
  @IsObject()
  payload?: unknown;
}

