import { ApiProperty } from '@nestjs/swagger';
import { IsString, MinLength } from 'class-validator';

export class ResetSettingsUserPasswordDto {
  @ApiProperty({ example: '123456' })
  @IsString()
  @MinLength(4)
  newPassword!: string;
}

