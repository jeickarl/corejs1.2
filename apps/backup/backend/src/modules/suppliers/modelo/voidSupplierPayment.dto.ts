import { ApiProperty } from '@nestjs/swagger';
import { IsString, MinLength } from 'class-validator';

export class VoidSupplierPaymentDto {
  @ApiProperty({ example: 'Motivo de anulación' })
  @IsString()
  @MinLength(1)
  reason!: string;
}

