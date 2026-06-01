import { ApiProperty } from '@nestjs/swagger';
import { IsString, MinLength } from 'class-validator';

export class CreateAccessoryDto {
  @ApiProperty({ example: 'Cargador' })
  @IsString()
  @MinLength(1)
  name!: string;
}

