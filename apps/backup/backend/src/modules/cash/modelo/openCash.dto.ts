import { ApiProperty } from '@nestjs/swagger';
import { IsNumber, Min } from 'class-validator';

export class OpenCashDto {
  @ApiProperty({ example: 0 })
  @IsNumber()
  @Min(0)
  initialAmount!: number;
}

