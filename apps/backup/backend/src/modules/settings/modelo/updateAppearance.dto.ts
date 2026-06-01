import { ApiProperty } from '@nestjs/swagger';
import { IsIn } from 'class-validator';

export class UpdateAppearanceDto {
  @ApiProperty({ enum: ['light', 'dark'] })
  @IsIn(['light', 'dark'])
  themeMode!: 'light' | 'dark';
}

