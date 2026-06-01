import { ApiProperty } from '@nestjs/swagger';

export class RegionalConfigDto {
  @ApiProperty({ example: 'COP' })
  currency!: string;

  @ApiProperty({ example: '$' })
  currencySymbol!: string;

  @ApiProperty({ example: true })
  taxEnabled!: boolean;

  @ApiProperty({ example: 'IVA' })
  taxName!: string;

  @ApiProperty({ example: 19 })
  taxRate!: number;

  @ApiProperty({ example: 0 })
  invoiceDueDaysDefault!: number;
}

