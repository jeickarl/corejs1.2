import { ApiProperty } from '@nestjs/swagger';
import { IsString, MinLength } from 'class-validator';

export class CancelInvoiceDto {
  @ApiProperty({ example: 'Cliente canceló' })
  @IsString()
  @MinLength(1)
  reason!: string;
}

