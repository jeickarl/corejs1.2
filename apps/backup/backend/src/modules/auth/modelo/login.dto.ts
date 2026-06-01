import { ApiProperty } from '@nestjs/swagger';
import { IsString, MinLength } from 'class-validator';

export class LoginDto {
  @ApiProperty({ example: 'admin@empresa.com' })
  @IsString()
  @MinLength(1)
  email!: string;

  @ApiProperty({ example: '12345678' })
  @IsString()
  @MinLength(1)
  password!: string;
}
