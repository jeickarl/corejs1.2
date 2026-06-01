import { ApiProperty } from '@nestjs/swagger';

export class AppearanceDto {
  @ApiProperty({ enum: ['light', 'dark'] })
  themeMode!: 'light' | 'dark';
}

